<?php

/**
 * scope_lab_lib.php — engine behind the Scope Lab panel.
 *
 * One "run" = one profile x one grant x one context:
 *
 *   1. register    dynamic-register a throwaway client carrying exactly the profile's scopes
 *                  (named "<site> ScopeLab <profile> <grant>", replaced on every run)
 *   2. token       obtain a token with the profile's requested scopes
 *                  (client_credentials and password are automatic; auth-code goes through the
 *                  browser and the consent screen, then scope_lab_callback.php)
 *   3. compare     requested vs granted, as effective c/r/u/d/s permissions, cross-checked
 *                  against the access-token JWT claim and the introspection endpoint
 *   4. probe       hit the API once per resource x permission and check the server enforces
 *                  what the token says -- and what the caller asked for
 *   5. refresh     same scope / superset (must fail) / subset (must narrow)
 *
 * Probes are non-destructive by construction: reads use _count=1 or a fixed fake id, writes
 * send an empty body `{}` to a create/update route. Authorisation runs before validation, so
 * 401/403 means "scope refused" and 400/404/422 means "scope accepted, body refused". A 2xx
 * on a write probe would mean a row was created; it is flagged loudly if it ever happens.
 *
 * Tokens stay in the PHP session; the browser only ever sees scope strings and verdicts.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/src/ScopeAlgebra.php';
require_once __DIR__ . '/src/JwkService.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha384;
use OpenEMR\ApiExplorer\ScopeLab\ScopeAlgebra;
use OpenEMR\Auth\JwkService;

const LAB_FAKE_ID = '00000000-0000-4000-8000-5c0be1ab0001';
const LAB_MAX_RUNS = 60;
const LAB_PROBE_CONCURRENCY = 8;

const LAB_GRANTS = [
    'client_credentials' => ['label' => 'Client Credentials (JWT)', 'contexts' => ['system'], 'interactive' => false],
    'auth_code_confidential' => ['label' => 'Auth Code — confidential', 'contexts' => ['user', 'patient'], 'interactive' => true],
    'auth_code_public' => ['label' => 'Auth Code — public (PKCE)', 'contexts' => ['user', 'patient'], 'interactive' => true],
    'password' => ['label' => 'Password (if enabled)', 'contexts' => ['user', 'patient'], 'interactive' => false],
];

// Standard API (api:oemr) routes per scope name. {puuid} and {id} get the fake id: a random
// uuid gets past routing to the scope check, which is all a probe needs.
const LAB_STANDARD_ROUTES = [
    'patient' => ['s' => '/patient', 'r' => '/patient/{id}', 'c' => '/patient', 'u' => '/patient/{id}'],
    'practitioner' => ['s' => '/practitioner', 'r' => '/practitioner/{id}', 'c' => '/practitioner', 'u' => '/practitioner/{id}'],
    'facility' => ['s' => '/facility', 'r' => '/facility/{id}', 'c' => '/facility', 'u' => '/facility/{id}'],
    'insurance_company' => ['s' => '/insurance_company', 'r' => '/insurance_company/{id}', 'c' => '/insurance_company', 'u' => '/insurance_company/{id}'],
    'encounter' => ['s' => '/patient/{puuid}/encounter', 'r' => '/patient/{puuid}/encounter/{id}', 'c' => '/patient/{puuid}/encounter', 'u' => '/patient/{puuid}/encounter/{id}'],
    'allergy' => ['s' => '/patient/{puuid}/allergy', 'r' => '/patient/{puuid}/allergy/{id}', 'c' => '/patient/{puuid}/allergy', 'u' => '/patient/{puuid}/allergy/{id}'],
    'medical_problem' => ['s' => '/patient/{puuid}/medical_problem', 'r' => '/patient/{puuid}/medical_problem/{id}', 'c' => '/patient/{puuid}/medical_problem', 'u' => '/patient/{puuid}/medical_problem/{id}'],
    'appointment' => ['s' => '/appointment', 'r' => '/appointment/{id}'],
    'prescription' => ['s' => '/prescription', 'r' => '/prescription/{id}'],
    'drug' => ['s' => '/drug', 'r' => '/drug/{id}'],
    'immunization' => ['s' => '/immunization', 'r' => '/immunization/{id}'],
    'procedure' => ['s' => '/procedure', 'r' => '/procedure/{id}'],
];

// Alternate code used for the "other category must be denied" granular probe.
const LAB_GRANULAR_ALTERNATES = [
    'laboratory' => 'vital-signs',
    'vital-signs' => 'laboratory',
    'problem-list-item' => 'encounter-diagnosis',
    'encounter-diagnosis' => 'problem-list-item',
    'health-concern' => 'problem-list-item',
];

// ------------------------------------------------------------------ configuration

/** @return array{base: array<string,string>, controls: array{fhir: list<string>, standard: list<string>}, profiles: array<string, array<string, mixed>>} */
function labConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/scope_lab_profiles.php';
    }
    return $config;
}

function labSite(): string
{
    return (string) ($_SESSION['selectedSite'] ?? 'localhost');
}

function labCallbackUri(): string
{
    return (string) preg_replace('~/[^/]+$~', '/scope_lab_callback.php', (string) $GLOBALS['ApiConfig']['REDIRECT_URI']);
}

function labSlug(string $value): string
{
    return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-');
}

function labClientName(string $profileId, string $grant): string
{
    return labSite() . " ScopeLab {$profileId} {$grant}";
}

function labClientFile(string $profileId, string $grant): string
{
    return __DIR__ . '/clients_keys/scopelab_' . labSlug(labSite()) . '_' . labSlug($grant) . '_' . labSlug($profileId) . '.json';
}

/** @return list<string> */
function labWritableFhir(): array
{
    return ScopeAlgebra::resources(ScopeAlgebra::split(FHIR_WRITE_SCOPES))['fhir'];
}

/** @return list<string> */
function labWritableStandard(): array
{
    return ScopeAlgebra::resources(ScopeAlgebra::split(STANDARD_API_WRITE_SCOPES))['standard'];
}

/**
 * Instantiates a profile for a context: substitutes {ctx}, prepends base scopes, applies strip.
 *
 * @param array{register?: string, request?: string}|null $custom
 * @return array{id: string, label: string, group: string, note: string, contexts: list<string>,
 *               registered: list<string>, requested: list<string>, expect: array<string, string>}
 */
function labInstantiate(string $profileId, string $context, ?array $custom = null): array
{
    $config = labConfig();
    if ($profileId === 'custom') {
        $profile = [
            'label' => 'custom',
            'group' => 'custom',
            'register' => (string) ($custom['register'] ?? ''),
            'request' => (string) ($custom['request'] ?? ''),
            'note' => 'Ad-hoc scope set from the Scope Lab custom row.',
        ];
        if (trim($profile['request']) === '') {
            unset($profile['request']);
        }
    } else {
        $profile = $config['profiles'][$profileId] ?? null;
        if (!is_array($profile)) {
            throw new InvalidArgumentException("Unknown profile {$profileId}");
        }
    }

    $base = ScopeAlgebra::split((string) ($config['base'][$context] ?? ''));
    $strip = (array) ($profile['strip'] ?? []);
    $expand = static function (string $scopes) use ($context, $base, $strip): array {
        $list = array_merge($base, ScopeAlgebra::split(str_replace('{ctx}', $context, $scopes)));
        return array_values(array_unique(array_diff($list, $strip)));
    };

    $registered = $expand((string) $profile['register']);
    $requested = isset($profile['request']) ? $expand((string) $profile['request']) : $registered;

    return [
        'id' => $profileId,
        'label' => (string) ($profile['label'] ?? $profileId),
        'group' => (string) ($profile['group'] ?? 'custom'),
        'note' => (string) ($profile['note'] ?? ''),
        'contexts' => array_values((array) ($profile['contexts'] ?? ['system', 'user', 'patient'])),
        'registered' => $registered,
        'requested' => $requested,
        'expect' => array_map('strval', (array) ($profile['expect'] ?? [])),
    ];
}

/**
 * Predicts registration and token outcomes from the published scope list.
 *
 * @param list<string> $registered
 * @param list<string> $requested
 * @param list<string>|null $published  null when discovery has not run
 * @param array<string, string> $overrides
 * @return array{unpublished: list<string>, invalid: list<array{scope: string, reason: ?string}>,
 *               version: string, expect: array{registration: string, token: string}, basis: string}
 */
function labPreflight(array $registered, array $requested, ?array $published, array $overrides): array
{
    $invalid = ScopeAlgebra::effective($registered)['invalid'];
    $unpublished = [];
    if ($published !== null) {
        $publishedSet = array_flip($published);
        foreach ($registered as $scope) {
            // Anything that is not a resource scope and not published is still checked:
            // a typo'd base scope is as much a registration failure as a bad resource scope.
            if (!isset($publishedSet[$scope])) {
                $unpublished[] = $scope;
            }
        }
    }

    $basis = [];
    $registration = 'accept';
    if ($invalid !== []) {
        $registration = 'reject';
        $basis[] = count($invalid) . ' malformed';
    }
    if ($unpublished !== []) {
        $registration = 'reject';
        $basis[] = count($unpublished) . ' not in scopes_supported';
    }
    if ($published === null) {
        $basis[] = 'discovery not run — publication unchecked';
    }
    $token = array_diff($requested, $registered) === [] ? 'accept' : 'reject_or_drop';

    return [
        'unpublished' => $unpublished,
        'invalid' => $invalid,
        'version' => ScopeAlgebra::versionOf($registered),
        'expect' => [
            'registration' => $overrides['registration'] ?? $registration,
            'token' => $overrides['token'] ?? $token,
        ],
        'basis' => $basis === [] ? 'all scopes published and well-formed' : implode('; ', $basis),
    ];
}

/** @return list<string>|null */
function labPublished(): ?array
{
    $discovery = $_SESSION['scope_lab']['discovery'][labSite()] ?? null;
    if (!is_array($discovery) || !isset($discovery['scopes_supported']) || !is_array($discovery['scopes_supported'])) {
        return null;
    }
    return array_values(array_map('strval', $discovery['scopes_supported']));
}

// ------------------------------------------------------------------ HTTP

/**
 * @param array<string, string> $headers
 * @return array{status: int, raw: string, json: mixed, ms: int, error: ?string, headers: array<string, string>}
 */
function labHttp(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $responseHeaders = [];
    $handle = curl_init($url);
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $start = microtime(true);
    $raw = curl_exec($handle);
    $ms = (int) round((microtime(true) - $start) * 1000);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = $raw === false ? curl_error($handle) : null;
    curl_close($handle);
    $raw = is_string($raw) ? $raw : '';

    return [
        'status' => $status,
        'raw' => $raw,
        'json' => $raw !== '' ? json_decode($raw, true) : null,
        'ms' => $ms,
        'error' => $error,
        'headers' => $responseHeaders,
    ];
}

/**
 * @param array<string, string> $fields
 * @return array{status: int, raw: string, json: mixed, ms: int, error: ?string, headers: array<string, string>}
 */
function labPostForm(string $url, array $fields): array
{
    return labHttp('POST', $url, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
    ], http_build_query($fields));
}

/**
 * Bounded-concurrency fan-out, input order preserved. Same shape as fhir_stress.php's.
 *
 * @param list<array{method: string, url: string, body: ?string}> $requests
 * @return list<array{status: int, ms: int, json: mixed, raw: string, error: ?string}>
 */
function labMulti(array $requests, string $access, int $concurrency = LAB_PROBE_CONCURRENCY): array
{
    if ($requests === []) {
        return [];
    }
    $multi = curl_multi_init();
    $results = [];
    $inFlight = [];
    $next = 0;
    $total = count($requests);

    $launch = static function (int $index) use (&$inFlight, $multi, $requests, $access): void {
        $request = $requests[$index];
        $handle = curl_init($request['url']);
        $headers = ["Authorization: Bearer {$access}", 'Accept: application/fhir+json, application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($request['body'] !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $request['body'];
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $opts);
        curl_multi_add_handle($multi, $handle);
        $inFlight[(int) spl_object_id($handle)] = ['handle' => $handle, 'index' => $index, 'start' => microtime(true)];
    };

    while ($next < $total && count($inFlight) < $concurrency) {
        $launch($next++);
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $key = (int) spl_object_id($handle);
            $slot = $inFlight[$key];
            $raw = curl_multi_getcontent($handle);
            $raw = is_string($raw) ? $raw : '';
            $results[$slot['index']] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'ms' => (int) round((microtime(true) - $slot['start']) * 1000),
                'json' => $raw !== '' ? json_decode($raw, true) : null,
                'raw' => $raw,
                'error' => $info['result'] !== CURLE_OK ? curl_error($handle) : null,
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($inFlight[$key]);
            if ($next < $total) {
                $launch($next++);
            }
        }
    } while ($inFlight !== []);
    curl_multi_close($multi);
    ksort($results);

    return array_values($results);
}

// ------------------------------------------------------------------ OAuth

function labAssertion(string $clientId): string
{
    $privateKeyPath = __DIR__ . '/clients_keys/' . labSite() . '_private.pem';
    if (!is_file($privateKeyPath)) {
        throw new RuntimeException('No site key pair yet — run Register Clients once for this site.');
    }
    $config = Configuration::forAsymmetricSigner(new Sha384(), InMemory::file($privateKeyPath), InMemory::empty());
    $now = new DateTimeImmutable();
    return $config->builder()
        ->issuedBy($clientId)
        ->relatedTo($clientId)
        ->permittedFor((string) $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'])
        ->identifiedBy(bin2hex(random_bytes(16)))
        ->issuedAt($now)
        ->expiresAt($now->modify('+5 minutes'))
        ->getToken($config->signer(), $config->signingKey())
        ->toString();
}

function labIsRemote(): bool
{
    return stripos(labSite(), 'remote-') !== false;
}

/**
 * Registers (replacing) the lab client for a profile/grant and saves its credentials.
 *
 * @param list<string> $registered
 * @return array{ok: bool, status: int, ms: int, client_id: ?string, error: ?string,
 *               echoed: list<string>|null, enabled: ?bool, note: ?string}
 */
function labRegister(string $profileId, string $grant, array $registered): array
{
    $name = labClientName($profileId, $grant);
    $file = labClientFile($profileId, $grant);
    $remote = labIsRemote();
    if (!$remote) {
        sqlStatementNoLog('DELETE FROM oauth_clients WHERE client_name = ?', [$name]);
    }
    if (is_file($file)) {
        @unlink($file);
    }

    $payload = [
        'client_name' => $name,
        'scope' => implode(' ', $registered),
        'contacts' => ['scope-lab@example.com'],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
    ];
    switch ($grant) {
        case 'client_credentials':
            $jwk = new JwkService(__DIR__, labSite());
            ob_start(); // ensureKeys() echoes progress lines meant for client_register.php
            $jwk->ensureKeys(false);
            ob_end_clean();
            $payload += [
                'application_type' => 'private',
                'token_endpoint_auth_method' => 'client_secret_post',
                'client_secret' => '',
                'grant_types' => ['client_credentials'],
                'redirect_uris' => [$GLOBALS['ApiConfig']['REDIRECT_URI']],
            ];
            if (!empty($GLOBALS['ApiConfig']['JWKS_LOCATION_URL'])) {
                $payload['jwks_uri'] = $GLOBALS['ApiConfig']['JWKS_LOCATION_URL'];
            } else {
                $payload['jwks'] = $jwk->loadJwks();
            }
            break;
        case 'auth_code_confidential':
            $payload += [
                'application_type' => 'private',
                'token_endpoint_auth_method' => 'client_secret_post',
                'client_secret' => '',
                'grant_types' => ['authorization_code'],
                'response_types' => ['code'],
                'redirect_uris' => [labCallbackUri()],
            ];
            break;
        case 'auth_code_public':
            $payload += [
                'application_type' => 'public',
                'token_endpoint_auth_method' => 'client_secret_basic',
                'grant_types' => ['authorization_code'],
                'response_types' => ['code'],
                'redirect_uris' => [labCallbackUri()],
            ];
            break;
        case 'password':
            $payload += [
                'application_type' => 'private',
                'token_endpoint_auth_method' => 'client_secret_post',
                'client_secret' => '',
                'grant_types' => ['password'],
                'redirect_uris' => [labCallbackUri()],
            ];
            break;
        default:
            throw new InvalidArgumentException("Unknown grant {$grant}");
    }

    $response = labHttp('POST', (string) $GLOBALS['ApiConfig']['REGISTER_CLIENT_ENDPOINT'], [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ], (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    $json = is_array($response['json']) ? $response['json'] : [];
    $ok = $response['status'] >= 200 && $response['status'] < 300 && !empty($json['client_id']);
    $result = [
        'ok' => $ok,
        'status' => $response['status'],
        'ms' => $response['ms'],
        'client_id' => $ok ? (string) $json['client_id'] : null,
        'error' => $ok ? null : labErrorText($response),
        'echoed' => isset($json['scope']) && is_string($json['scope']) ? ScopeAlgebra::split($json['scope']) : null,
        'enabled' => null,
        'note' => null,
    ];
    if (!$ok) {
        return $result;
    }

    file_put_contents($file, json_encode($json + ['scope_lab_grant' => $grant], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($remote) {
        $result['note'] = 'Remote site: client left disabled here. Enable it on that server if the token call is refused.';
    } else {
        // Enables in THIS install's database. For a site that is a different install on the
        // same host (localhost-dev etc.) enable it there if the token call says disabled.
        sqlStatementNoLog('UPDATE oauth_clients SET is_enabled = 1 WHERE client_id = ?', [$result['client_id']]);
        $result['enabled'] = true;
    }

    return $result;
}

/** @return array<string, mixed> */
function labLoadClient(string $profileId, string $grant): array
{
    $file = labClientFile($profileId, $grant);
    if (!is_file($file)) {
        throw new RuntimeException('Lab client not registered for this profile/grant.');
    }
    $client = json_decode((string) file_get_contents($file), true);
    return is_array($client) ? $client : [];
}

/**
 * @param array{status: int, raw: string, json: mixed, error: ?string} $response
 */
function labErrorText(array $response): string
{
    if ($response['error'] !== null && $response['error'] !== '') {
        return $response['error'];
    }
    $json = $response['json'];
    if (is_array($json)) {
        foreach (['error_description', 'message', 'hint', 'error'] as $key) {
            if (!empty($json[$key]) && is_string($json[$key])) {
                return ($key === 'error_description' && !empty($json['error']) ? $json['error'] . ': ' : '') . $json[$key];
            }
        }
        if (isset($json['issue'][0]['diagnostics']) && is_string($json['issue'][0]['diagnostics'])) {
            return $json['issue'][0]['diagnostics'];
        }
    }
    return substr(trim(strip_tags($response['raw'])), 0, 300) ?: 'HTTP ' . $response['status'];
}

/**
 * Normalises a token-endpoint response into the run's token block.
 *
 * @param array{status: int, raw: string, json: mixed, ms: int, error: ?string} $response
 * @param list<string> $requested
 * @return array{block: array<string, mixed>, access: ?string, refresh: ?string}
 */
function labTokenResult(array $response, array $requested): array
{
    $json = is_array($response['json']) ? $response['json'] : [];
    $ok = $response['status'] >= 200 && $response['status'] < 300 && !empty($json['access_token']);
    $access = $ok ? (string) $json['access_token'] : null;
    $scopeReported = isset($json['scope']) && is_string($json['scope']);
    $claims = $access !== null ? labJwtClaims($access) : null;
    $claimScopes = null;
    if (is_array($claims)) {
        if (isset($claims['scopes']) && is_array($claims['scopes'])) {
            $claimScopes = array_values(array_map('strval', $claims['scopes']));
        } elseif (isset($claims['scope']) && is_string($claims['scope'])) {
            $claimScopes = ScopeAlgebra::split($claims['scope']);
        }
    }
    // What the server says it granted: the response's scope, else the JWT claim. RFC 6749
    // allows omitting scope when it equals the request, so fall back to that last.
    $granted = $scopeReported ? ScopeAlgebra::split((string) $json['scope']) : ($claimScopes ?? ($ok ? $requested : []));

    // OpenEMR leaves api:* out of the token response's scope string on purpose
    // (IdTokenSMARTResponse::getScopeString(), for ONC), while the token itself carries them
    // and the API gates on them. Put back what the JWT claim holds so the grant is judged on
    // what the token actually authorises, and record what was hidden.
    $hiddenApi = [];
    if ($scopeReported && is_array($claimScopes)) {
        foreach ($claimScopes as $scope) {
            if (str_starts_with($scope, 'api:') && !in_array($scope, $granted, true)) {
                $granted[] = $scope;
                $hiddenApi[] = $scope;
            }
        }
    }

    return [
        'block' => [
            'ok' => $ok,
            'status' => $response['status'],
            'ms' => $response['ms'],
            'error' => $ok ? null : labErrorText($response),
            'granted' => $granted,
            'grantedSource' => ($scopeReported ? 'token response' : ($claimScopes !== null ? 'JWT claim' : 'assumed = requested'))
                . ($hiddenApi !== [] ? ' + ' . implode(' ', $hiddenApi) . ' from JWT (omitted from response by design)' : ''),
            'hiddenApi' => $hiddenApi,
            'claimScopes' => $claimScopes,
            'hasRefresh' => !empty($json['refresh_token']),
            'patient' => isset($json['patient']) && is_string($json['patient']) ? $json['patient'] : null,
            'expiresIn' => isset($json['expires_in']) ? (int) $json['expires_in'] : null,
        ],
        'access' => $access,
        'refresh' => $ok && !empty($json['refresh_token']) ? (string) $json['refresh_token'] : null,
    ];
}

/** @return array<string, mixed>|null */
function labJwtClaims(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $claims = $payload !== false ? json_decode($payload, true) : null;
    return is_array($claims) ? $claims : null;
}

/**
 * Client authentication fields for token / introspection calls.
 *
 * @param array<string, mixed> $client
 * @return array<string, string>
 */
function labClientAuth(array $client, string $grant): array
{
    $fields = ['client_id' => (string) $client['client_id']];
    if ($grant === 'client_credentials') {
        $fields['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
        $fields['client_assertion'] = labAssertion((string) $client['client_id']);
    } elseif (!empty($client['client_secret']) && $grant !== 'auth_code_public') {
        $fields['client_secret'] = (string) $client['client_secret'];
    }
    return $fields;
}

// ------------------------------------------------------------------ analysis

/**
 * Introspection, JWT cross-check, probes and refresh tests for a run that holds a token.
 *
 * @param array<string, mixed> $run
 * @param array{access: ?string, refresh: ?string} $tokens
 * @param array{probeWrites?: bool, refresh?: bool} $options
 * @return array{run: array<string, mixed>, refresh: ?string}
 */
function labAnalyze(array $run, array $tokens, array $options): array
{
    $client = labLoadClient((string) $run['profile'], (string) $run['grant']);
    $granted = (array) $run['token']['granted'];
    $expectedGranted = (array) $run['expectedGranted'];

    // Grant comparison -- the heart of it.
    $run['grantDiff'] = ScopeAlgebra::diff($expectedGranted, $granted);
    if (is_array($run['token']['claimScopes'] ?? null)) {
        $run['jwtDiff'] = ScopeAlgebra::diff($granted, (array) $run['token']['claimScopes']);
    }

    // Introspection -- a third opinion on what the token carries.
    $run['introspect'] = labIntrospect($client, (string) $run['grant'], (string) $tokens['access'], $granted);

    // Enforcement probes.
    $run['probes'] = labProbes($run, (string) $tokens['access'], (bool) ($options['probeWrites'] ?? true));

    // Refresh behaviour.
    $refresh = $tokens['refresh'];
    $run['refresh'] = [];
    if (($options['refresh'] ?? true) && $refresh !== null) {
        [$run['refresh'], $refresh] = labRefreshTests($client, (string) $run['grant'], $refresh, $granted, (array) $run['registered']);
    }

    $run['verdicts'] = labVerdicts($run);
    return ['run' => $run, 'refresh' => $refresh];
}

/**
 * @param array<string, mixed> $client
 * @param list<string> $granted
 * @return array<string, mixed>
 */
function labIntrospect(array $client, string $grant, string $access, array $granted): array
{
    $discovery = $_SESSION['scope_lab']['discovery'][labSite()] ?? [];
    $endpoint = is_array($discovery) && !empty($discovery['introspection_endpoint'])
        ? (string) $discovery['introspection_endpoint']
        : (string) preg_replace('~/token$~', '/introspect', (string) $GLOBALS['ApiConfig']['TOKEN_ENDPOINT']);
    try {
        $fields = labClientAuth($client, $grant) + ['token' => $access, 'token_type_hint' => 'access_token'];
    } catch (Throwable $e) {
        return ['ran' => false, 'error' => $e->getMessage()];
    }
    $response = labPostForm($endpoint, $fields);
    $json = is_array($response['json']) ? $response['json'] : [];
    if ($response['status'] !== 200 || !array_key_exists('active', $json)) {
        return ['ran' => true, 'ok' => false, 'status' => $response['status'], 'error' => labErrorText($response)];
    }
    $scopes = isset($json['scope']) && is_string($json['scope']) ? ScopeAlgebra::split($json['scope']) : null;
    return [
        'ran' => true,
        'ok' => true,
        'status' => 200,
        'active' => (bool) $json['active'],
        'scopes' => $scopes,
        'diff' => $scopes !== null ? ScopeAlgebra::diff($granted, $scopes) : null,
    ];
}

/**
 * Builds and fires the probe matrix.
 *
 * Every probe is scored twice:
 *   vsGranted   -- does the server enforce the scopes it SAID it granted? (reliability)
 *   vsExpected  -- does the caller get what it ASKED for? (a lost write shows up here)
 *
 * @param array<string, mixed> $run
 * @return list<array<string, mixed>>
 */
function labProbes(array $run, string $access, bool $probeWrites): array
{
    $config = labConfig();
    $granted = (array) $run['token']['granted'];
    $expected = (array) $run['expectedGranted'];
    $universe = array_merge((array) $run['registered'], (array) $run['requested'], $granted);
    $resources = ScopeAlgebra::resources($universe);

    $fhir = array_values(array_unique(array_merge($resources['fhir'], $config['controls']['fhir'])));
    $standard = $resources['standard'];
    $gateOemr = in_array('api:oemr', $universe, true);
    if ($gateOemr || $standard !== []) {
        $standard = array_values(array_unique(array_merge($standard, $config['controls']['standard'])));
    }
    $controlSet = array_flip(array_merge(
        array_diff($config['controls']['fhir'], $resources['fhir']),
        array_diff($config['controls']['standard'], $resources['standard'])
    ));

    $fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
    $apiBase = rtrim((string) $GLOBALS['ApiConfig']['API_SERVER_URL'], '/');
    $writableFhir = array_flip(labWritableFhir());
    $writableStandard = array_flip(labWritableStandard());

    $probes = [];
    $add = static function (string $api, string $resource, string $perm, string $method, string $url, ?string $query, string $label) use (&$probes, $controlSet): void {
        $probes[] = [
            'api' => $api,
            'resource' => $resource,
            'perm' => $perm,
            'method' => $method,
            'url' => $url,
            'query' => $query,
            'label' => $label,
            'control' => isset($controlSet[$resource]),
        ];
    };

    // Phase 1: searches (their results give real ids for the read probes).
    foreach ($fhir as $resource) {
        $add('fhir', $resource, 's', 'GET', "{$fhirBase}/{$resource}?_count=1", null, 'search');
    }
    $granularSeen = [];
    foreach (array_merge($granted, $expected, (array) $run['requested']) as $scope) {
        $parsed = ScopeAlgebra::parse($scope);
        if ($parsed['kind'] !== 'resource' || $parsed['constraint'] === null || !in_array('s', $parsed['perms'], true)) {
            continue;
        }
        $key = $parsed['resource'] . '?' . $parsed['constraint'];
        if (isset($granularSeen[$key])) {
            continue;
        }
        $granularSeen[$key] = true;
        $query = (string) $parsed['constraint'];
        $add('fhir', (string) $parsed['resource'], 's', 'GET', "{$fhirBase}/{$parsed['resource']}?{$query}&_count=1", $query, 'search, matching constraint');
        $alternate = labAlternateConstraint($query);
        if ($alternate !== null) {
            $add('fhir', (string) $parsed['resource'], 's', 'GET', "{$fhirBase}/{$parsed['resource']}?{$alternate}&_count=1", $alternate, 'search, other value');
        }
    }
    foreach ($standard as $resource) {
        $routes = LAB_STANDARD_ROUTES[$resource] ?? null;
        if ($routes !== null && isset($routes['s'])) {
            $add('standard', $resource, 's', 'GET', $apiBase . labStandardPath($routes['s']), null, 'list');
        }
    }
    $phase1 = labMulti(array_map(static fn(array $p): array => ['method' => $p['method'], 'url' => $p['url'], 'body' => null], $probes), $access);

    $ids = [];
    foreach ($probes as $i => $probe) {
        if ($probe['api'] === 'fhir' && $probe['query'] === null) {
            $id = $phase1[$i]['json']['entry'][0]['resource']['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[$probe['resource']] = $id;
            }
        }
    }

    // Phase 2: read-by-id and (optionally) the empty-body write probes.
    $phase2Start = count($probes);
    foreach ($fhir as $resource) {
        $id = $ids[$resource] ?? LAB_FAKE_ID;
        $add('fhir', $resource, 'r', 'GET', "{$fhirBase}/{$resource}/" . rawurlencode($id), null, isset($ids[$resource]) ? 'read (real id)' : 'read (fake id)');
        if ($probeWrites && isset($writableFhir[$resource])) {
            $add('fhir', $resource, 'c', 'POST', "{$fhirBase}/{$resource}", null, 'create, empty body');
            $add('fhir', $resource, 'u', 'PUT', "{$fhirBase}/{$resource}/" . LAB_FAKE_ID, null, 'update, empty body');
        }
    }
    foreach ($standard as $resource) {
        $routes = LAB_STANDARD_ROUTES[$resource] ?? [];
        if (isset($routes['r'])) {
            $add('standard', $resource, 'r', 'GET', $apiBase . labStandardPath($routes['r']), null, 'read (fake id)');
        }
        if ($probeWrites && isset($writableStandard[$resource])) {
            if (isset($routes['c'])) {
                $add('standard', $resource, 'c', 'POST', $apiBase . labStandardPath($routes['c']), null, 'create, empty body');
            }
            if (isset($routes['u'])) {
                $add('standard', $resource, 'u', 'PUT', $apiBase . labStandardPath($routes['u']), null, 'update, empty body');
            }
        }
    }
    $phase2 = labMulti(array_map(
        static fn(array $p): array => ['method' => $p['method'], 'url' => $p['url'], 'body' => $p['method'] === 'GET' ? null : '{}'],
        array_slice($probes, $phase2Start)
    ), $access);
    $responses = array_merge($phase1, $phase2);

    // Score.
    foreach ($probes as $i => &$probe) {
        $response = $responses[$i];
        $gate = $probe['api'] === 'fhir' ? 'api:fhir' : 'api:oemr';
        $probe['status'] = $response['status'];
        $probe['ms'] = $response['ms'];
        $probe['outcome'] = labClassify($response['status']);
        $probe['predictGranted'] = ScopeAlgebra::predict($granted, $gate, $probe['resource'], $probe['perm'], $probe['query']);
        $probe['predictExpected'] = ScopeAlgebra::predict($expected, $gate, $probe['resource'], $probe['perm'], $probe['query']);
        $probe['vsGranted'] = labScore($probe['predictGranted'], $probe['outcome']);
        $probe['vsExpected'] = labScore($probe['predictExpected'], $probe['outcome']);
        $probe['detail'] = $probe['outcome'] === 'allow' && $probe['method'] === 'POST'
            ? 'WROTE A ROW — empty-body create was accepted'
            : ($response['status'] >= 400 || $response['status'] === 0 ? substr(labErrorText($response), 0, 180) : null);
        if ($probe['outcome'] === 'allow' && $probe['method'] === 'POST') {
            $probe['vsGranted'] = 'fail';
        }
        // A 2xx search under a constraint-only grant is right only when nothing outside the
        // constraint comes back. The status cannot tell a filtered result from a leak, so the
        // returned entries are checked.
        if ($probe['api'] === 'fhir' && $probe['perm'] === 's' && $probe['outcome'] === 'allow') {
            $audit = labFilterAudit($response['json'], ScopeAlgebra::constraintsFor($granted, $probe['resource'], 's'));
            if ($audit !== null) {
                $probe['vsGranted'] = $audit['outside'] > 0 ? 'fail' : 'pass';
                $probe['detail'] = $audit['summary'];
                if ($audit['outside'] === 0 && $probe['predictGranted'] !== 'allow') {
                    $probe['outcome'] = 'filtered';
                }
            }
            $auditExpected = labFilterAudit($response['json'], ScopeAlgebra::constraintsFor($expected, $probe['resource'], 's'));
            if ($auditExpected !== null) {
                $probe['vsExpected'] = $auditExpected['outside'] > 0 ? 'fail' : 'pass';
            }
        }
        $probe['path'] = (string) preg_replace('~^https?://[^/]+~', '', $probe['url']);
        unset($probe['url']);
    }
    unset($probe);

    return $probes;
}

/**
 * Checks the entries of a search Bundle against the constraints a grant is limited to.
 * Null when there is nothing to check: the grant is unrestricted, or the body is not a Bundle.
 *
 * @param list<string>|null $constraints from ScopeAlgebra::constraintsFor()
 * @return array{entries: int, outside: int, summary: string}|null
 */
function labFilterAudit(mixed $json, ?array $constraints): ?array
{
    if ($constraints === null || !is_array($json) || ($json['resourceType'] ?? null) !== 'Bundle') {
        return null;
    }
    $entries = 0;
    $outside = 0;
    foreach (is_array($json['entry'] ?? null) ? $json['entry'] : [] as $entry) {
        $resource = is_array($entry) && is_array($entry['resource'] ?? null) ? $entry['resource'] : null;
        if ($resource === null) {
            continue;
        }
        $entries++;
        $admitted = false;
        foreach ($constraints as $constraint) {
            if (ScopeAlgebra::resourceSatisfies($resource, $constraint)) {
                $admitted = true;
                break;
            }
        }
        if (!$admitted) {
            $outside++;
        }
    }
    if ($outside > 0) {
        $summary = "LEAK: {$outside} of {$entries} returned entries are outside the granted constraint";
    } elseif ($entries === 0) {
        $summary = 'filtered: no entries returned';
    } else {
        $summary = "filtered: {$entries} returned, all within the granted constraint";
    }

    return ['entries' => $entries, 'outside' => $outside, 'summary' => $summary];
}

function labStandardPath(string $template): string
{
    return str_replace(['{id}', '{puuid}'], [LAB_FAKE_ID, LAB_FAKE_ID], $template);
}

function labAlternateConstraint(string $constraint): ?string
{
    if (preg_match('~^(.*\|)([A-Za-z0-9-]+)$~', $constraint, $m) !== 1) {
        return null;
    }
    $alternate = LAB_GRANULAR_ALTERNATES[$m[2]] ?? 'scope-lab-nomatch';
    return $m[1] . $alternate;
}

/**
 * allow     2xx
 * filtered  2xx search under a constraint-only grant that returned nothing outside the
 *           constraint (set by labProbes after labFilterAudit, not here)
 * passed    authorisation passed, request refused for another reason (400/404/405/409/410/412/422)
 * deny      401/403
 * error     transport failure or 5xx -- says nothing about scopes
 */
function labClassify(int $status): string
{
    if ($status >= 200 && $status < 300) {
        return 'allow';
    }
    if ($status === 401 || $status === 403) {
        return 'deny';
    }
    if (in_array($status, [400, 404, 405, 409, 410, 412, 422], true)) {
        return 'passed';
    }
    return 'error';
}

function labScore(string $prediction, string $outcome): string
{
    if ($outcome === 'error') {
        return 'warn';
    }
    if ($prediction === 'either') {
        return 'info';
    }
    $allowed = $outcome === 'allow' || $outcome === 'passed';
    return ($prediction === 'allow') === $allowed ? 'pass' : 'fail';
}

/**
 * same      no scope param  -> must come back equivalent to the original grant
 * superset  one extra scope -> must be refused (invalid_scope); if it is granted, escalation
 * subset    narrower scope  -> must come back narrowed to exactly that
 * Run in that order: a successful subset refresh narrows the refresh token for good.
 *
 * @param array<string, mixed> $client
 * @param list<string> $granted
 * @param list<string> $registered
 * @return array{0: list<array<string, mixed>>, 1: ?string}
 */
function labRefreshTests(array $client, string $grant, string $refresh, array $granted, array $registered): array
{
    $results = [];
    $tokenEndpoint = (string) $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'];
    $auth = labClientAuth($client, $grant);

    $call = static function (?array $scope) use (&$refresh, $tokenEndpoint, $auth): array {
        $fields = $auth + ['grant_type' => 'refresh_token', 'refresh_token' => (string) $refresh];
        if ($scope !== null) {
            $fields['scope'] = implode(' ', $scope);
        }
        $response = labPostForm($tokenEndpoint, $fields);
        $parsed = labTokenResult($response, $scope ?? []);
        if ($parsed['refresh'] !== null) {
            $refresh = $parsed['refresh'];
        }
        return $parsed['block'];
    };

    // 1. same
    $block = $call(null);
    $diff = $block['ok'] ? ScopeAlgebra::diff($granted, (array) $block['granted']) : null;
    $results[] = [
        'name' => 'same scope',
        'requested' => null,
        'expected' => 'equivalent to original grant',
        'token' => $block,
        'diff' => $diff,
        'verdict' => $block['ok'] && $diff !== null && $diff['gained'] === [] && $diff['lost'] === [] ? 'pass' : 'fail',
    ];
    if (!$block['ok']) {
        return [$results, $refresh];
    }

    // 2. superset -- one published resource scope the client was not registered for.
    $extra = labExtraScope($granted, $registered);
    if ($extra !== null) {
        $scope = array_merge($granted, [$extra]);
        $block = $call($scope);
        $escalated = $block['ok'] && in_array($extra, (array) $block['granted'], true);
        $results[] = [
            'name' => 'superset',
            'requested' => $scope,
            'expected' => "refused (adds {$extra})",
            'token' => $block,
            'diff' => $block['ok'] ? ScopeAlgebra::diff($granted, (array) $block['granted']) : null,
            'verdict' => !$block['ok'] ? 'pass' : ($escalated ? 'fail' : 'warn'),
        ];
    }

    // 3. subset -- the first resource scope plus every non-resource scope.
    $subset = [];
    $keptResource = false;
    foreach ($granted as $scope) {
        $kind = ScopeAlgebra::parse($scope)['kind'];
        if ($kind !== 'resource') {
            $subset[] = $scope;
        } elseif (!$keptResource) {
            $subset[] = $scope;
            $keptResource = true;
        }
    }
    if ($keptResource && count($subset) < count($granted)) {
        $block = $call($subset);
        $diff = $block['ok'] ? ScopeAlgebra::diff($subset, (array) $block['granted']) : null;
        $results[] = [
            'name' => 'subset',
            'requested' => $subset,
            'expected' => 'narrowed to exactly the subset',
            'token' => $block,
            'diff' => $diff,
            'verdict' => $block['ok'] && $diff !== null && $diff['gained'] === [] && $diff['lost'] === [] ? 'pass' : 'fail',
        ];
    }

    return [$results, $refresh];
}

/**
 * @param list<string> $granted
 * @param list<string> $registered
 */
function labExtraScope(array $granted, array $registered): ?string
{
    $context = 'user';
    foreach ($granted as $scope) {
        $parsed = ScopeAlgebra::parse($scope);
        if ($parsed['kind'] === 'resource') {
            $context = (string) $parsed['context'];
            break;
        }
    }
    // Pick a resource the grant does not touch at all, so the extra scope is a real widening
    // and not a respelling of something already held (Patient.read beside Patient.rs).
    $held = array_flip(array_merge(
        ScopeAlgebra::resources($granted)['fhir'],
        ScopeAlgebra::resources($granted)['standard'],
        ScopeAlgebra::resources($registered)['fhir'],
        ScopeAlgebra::resources($registered)['standard']
    ));
    $published = labPublished() ?? [];
    foreach ($published as $scope) {
        $parsed = ScopeAlgebra::parse($scope);
        if (
            $parsed['kind'] === 'resource' && $parsed['context'] === $context && $parsed['valid']
            && $parsed['constraint'] === null && $parsed['resource'] !== '*' && !isset($held[(string) $parsed['resource']])
        ) {
            return $scope;
        }
    }
    foreach (['Practitioner', 'Organization', 'Immunization'] as $resource) {
        if (!isset($held[$resource])) {
            return "{$context}/{$resource}.read";
        }
    }
    return null;
}

/**
 * Collapses a run into verdicts: pass | fail | warn | info | skip per stage, plus overall.
 *
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function labVerdicts(array $run): array
{
    $v = [];
    $expect = (array) $run['preflight']['expect'];

    $reg = $run['registration'] ?? null;
    if (!is_array($reg)) {
        $v['registration'] = 'skip';
    } else {
        $v['registration'] = ($expect['registration'] === 'accept') === (bool) $reg['ok'] ? 'pass' : 'fail';
        if ($reg['ok'] && is_array($reg['echoed'] ?? null)) {
            $echo = ScopeAlgebra::diff((array) $run['registered'], (array) $reg['echoed']);
            if (!$echo['equivalent'] && $v['registration'] === 'pass') {
                $v['registration'] = 'warn';
            }
        }
    }

    $token = $run['token'] ?? null;
    if (!is_array($token)) {
        $v['token'] = 'skip';
    } else {
        $v['token'] = match ($expect['token']) {
            'accept' => $token['ok'] ? 'pass' : 'fail',
            'reject' => $token['ok'] ? 'fail' : 'pass',
            default => 'pass', // reject_or_drop: either is fine; escalation is caught by the grant diff
        };
    }

    $diff = $run['grantDiff'] ?? null;
    if (!is_array($diff)) {
        $v['grant'] = 'skip';
    } elseif ($diff['gained'] !== [] || $diff['gainedGranular'] !== []) {
        $v['grant'] = 'fail';
    } elseif ($diff['lost'] !== [] || $diff['lostGranular'] !== []) {
        $v['grant'] = 'fail';
    } elseif ($diff['lostOther'] !== [] || $diff['gainedOther'] !== []) {
        $v['grant'] = 'warn';
    } else {
        $v['grant'] = 'pass';
    }

    $jwt = $run['jwtDiff'] ?? null;
    $v['jwt'] = is_array($jwt) ? ($jwt['equivalent'] ? 'pass' : 'fail') : 'skip';

    $intro = $run['introspect'] ?? null;
    if (!is_array($intro) || empty($intro['ran'])) {
        $v['introspect'] = 'skip';
    } elseif (empty($intro['ok'])) {
        $v['introspect'] = 'warn';
    } elseif (empty($intro['active'])) {
        $v['introspect'] = 'fail';
    } else {
        $v['introspect'] = is_array($intro['diff'] ?? null) ? ($intro['diff']['equivalent'] ? 'pass' : 'fail') : 'info';
    }

    $counts = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];
    $countsExpected = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];
    foreach ((array) ($run['probes'] ?? []) as $probe) {
        $counts[$probe['vsGranted']]++;
        $countsExpected[$probe['vsExpected']]++;
    }
    $v['probeCounts'] = $counts;
    $v['probeCountsExpected'] = $countsExpected;
    $v['probes'] = empty($run['probes']) ? 'skip' : ($counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass'));

    $refreshVerdicts = array_column((array) ($run['refresh'] ?? []), 'verdict');
    $v['refresh'] = $refreshVerdicts === [] ? 'skip'
        : (in_array('fail', $refreshVerdicts, true) ? 'fail' : (in_array('warn', $refreshVerdicts, true) ? 'warn' : 'pass'));

    $all = [$v['registration'], $v['token'], $v['grant'], $v['jwt'], $v['introspect'], $v['probes'], $v['refresh']];
    $v['overall'] = in_array('fail', $all, true) ? 'fail' : (in_array('warn', $all, true) ? 'warn' : 'pass');

    return $v;
}

// ------------------------------------------------------------------ run storage

/**
 * Creates the skeleton of a run.
 *
 * @param array{register?: string, request?: string}|null $custom
 * @return array<string, mixed>
 */
function labNewRun(string $profileId, string $grant, string $context, ?array $custom): array
{
    if (!isset(LAB_GRANTS[$grant])) {
        throw new InvalidArgumentException("Unknown grant {$grant}");
    }
    if (!in_array($context, LAB_GRANTS[$grant]['contexts'], true)) {
        throw new InvalidArgumentException("Context {$context} does not apply to {$grant}");
    }
    $profile = labInstantiate($profileId, $context, $custom);
    $preflight = labPreflight($profile['registered'], $profile['requested'], labPublished(), $profile['expect']);

    return [
        'id' => date('His') . '-' . bin2hex(random_bytes(3)),
        'created' => date('c'),
        'site' => labSite(),
        'profile' => $profileId,
        'label' => $profile['label'],
        'group' => $profile['group'],
        'note' => $profile['note'],
        'grant' => $grant,
        'grantLabel' => LAB_GRANTS[$grant]['label'],
        'context' => $context,
        'registered' => $profile['registered'],
        'requested' => $profile['requested'],
        // What a correct server grants: what was asked for, limited to what was registered.
        'expectedGranted' => array_values(array_intersect($profile['requested'], $profile['registered'])),
        'preflight' => $preflight,
        'registration' => null,
        'token' => null,
        'grantDiff' => null,
        'jwtDiff' => null,
        'introspect' => null,
        'probes' => [],
        'refresh' => [],
        'verdicts' => null,
    ];
}

/**
 * @param array<string, mixed> $run
 */
function labStoreRun(array $run, ?string $access = null, ?string $refresh = null): void
{
    // Effective-permission views for the panel's c/r/u/d/s grid, computed here so the
    // browser never needs its own copy of the scope grammar.
    $expected = ScopeAlgebra::effective((array) $run['expectedGranted']);
    $granted = is_array($run['token'] ?? null) && !empty($run['token']['ok'])
        ? ScopeAlgebra::effective((array) $run['token']['granted'])
        : null;
    $run['effective'] = [
        'expected' => ['plain' => $expected['plain'], 'granular' => $expected['granular']],
        'granted' => $granted !== null ? ['plain' => $granted['plain'], 'granular' => $granted['granular']] : null,
    ];
    $_SESSION['scope_lab']['runs'][$run['id']] = $run;
    if ($access !== null || $refresh !== null) {
        $_SESSION['scope_lab']['tokens'][$run['id']] = ['access' => $access, 'refresh' => $refresh];
    }
    $runs = &$_SESSION['scope_lab']['runs'];
    while (count($runs) > LAB_MAX_RUNS) {
        $oldest = array_key_first($runs);
        unset($runs[$oldest], $_SESSION['scope_lab']['tokens'][$oldest]);
    }
}
