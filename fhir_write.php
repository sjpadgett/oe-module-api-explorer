<?php

/**
 * fhir_write.php — FHIR write (POST/PUT) driver for the API Explorer.
 *
 * AJAX endpoint that SHARES the Explorer's browser session and OAuth token
 * ($_SESSION['access_token'], $_SESSION['selectedSite']). Like group_export.php and
 * scope_resources.php it bootstraps from session + config only -- it does NOT pull
 * interface/globals.php, so there's no HTTP_HOST / site-id / CLI problem.
 *
 * The bearer token never leaves the server: the browser posts a body, this file
 * attaches the Authorization header.
 *
 * Actions (?action=):
 *   catalog          the write matrix from fhir_write_templates.php, plus the scopes the
 *                    current token actually carries, so the UI can grey out what will 401
 *   context          resolve live uuids to fill the body placeholders (patient,
 *                    practitioner, encounter, organizations, facility, questionnaire)
 *   send             POST body {resource, verb, id?, body} -> perform the write, return
 *                    status + response body + Location
 *   verify           ?resource=..&id=..  read the resource back (by id, or by _id search
 *                    for the resources that have no read-by-id route)
 *   seed             create the prerequisites the bodies reference -- patient, practitioner,
 *                    facility, insurer, encounter, questionnaire -- by POSTing them through the
 *                    same API under test, so the workbench works on an install with no demo data
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once __DIR__ . '/src/ScopeAlgebra.php';

use OpenEMR\ApiExplorer\ScopeLab\ScopeAlgebra;

/** @var array<string, array<string, mixed>> $writeMatrix */
$writeMatrix = require __DIR__ . '/fhir_write_templates.php';

/** @var array<string, array<string, mixed>> $seedFixtures */
$seedFixtures = require __DIR__ . '/fhir_write_seeds.php';

$access = (string) ($_SESSION['access_token'] ?? '');
if ($access === '') {
    http_response_code(401);
    echo json_encode([
        'error' => 'No access token in session. Obtain a token in the Explorer first '
            . '(Auth Code with a confidential/limited client carries the user/<Resource>.write scopes).',
    ]);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$action = (string) ($_GET['action'] ?? 'catalog');

/**
 * @param array<int, string> $headers
 * @return array{code:int, body:string, location:string}
 */
function fhirCall(string $method, string $url, string $access, array $headers = [], ?string $payload = null): array
{
    $ch = curl_init($url);
    $location = '';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer {$access}"], $headers),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$location) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
            return strlen($header);
        },
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($code === 0 && $curlError !== '') {
        return ['code' => 0, 'body' => json_encode(['transport_error' => $curlError]) ?: '', 'location' => ''];
    }

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'location' => $location];
}

/**
 * First entry id out of a search bundle, optionally the first entry matching $pick.
 *
 * Returns the outcome rather than just the id: a null id can mean the read was refused
 * (no `.read` scope for that resource), that the search succeeded but the database holds
 * no such records yet, or that records exist but none is of the required kind. Those three
 * need completely different fixes, so the caller gets enough to say which one happened.
 *
 * With $strict the pick must match: returning some other entry would quietly hand back a
 * resource of the wrong kind (a provider Organization standing in for an insurer, say),
 * which then fails much later as an opaque validation error.
 *
 * @param callable(array<string, mixed>): bool|null $pick
 * @return array{id: ?string, ids: list<string>, code: int, entries: int, matched: bool}
 */
function firstBundleEntry(string $url, string $access, ?callable $pick = null, bool $strict = false): array
{
    $r = fhirCall('GET', $url, $access, ['Accept: application/fhir+json']);
    $outcome = ['id' => null, 'ids' => [], 'code' => $r['code'], 'entries' => 0, 'matched' => false];
    if ($r['code'] !== 200) {
        return $outcome;
    }
    $bundle = json_decode($r['body'], true);
    if (!is_array($bundle)) {
        return $outcome;
    }
    $fallback = null;
    foreach (($bundle['entry'] ?? []) as $entry) {
        $resource = $entry['resource'] ?? null;
        if (!is_array($resource) || !is_string($resource['id'] ?? null)) {
            continue;
        }
        $outcome['entries']++;
        $fallback ??= $resource['id'];
        if ($pick === null || $pick($resource)) {
            $outcome['matched'] = true;
            $outcome['ids'][] = $resource['id'];
            $outcome['id'] ??= $resource['id'];
        }
    }
    if ($outcome['id'] === null && !$strict) {
        $outcome['id'] = $fallback;
    }

    return $outcome;
}

/**
 * Plain-language reason a context key could not be resolved, or null when it was.
 *
 * @param array{id: ?string, code: int, entries: int, matched: bool} $outcome
 */
function contextFailureReason(array $outcome, string $resource, bool $strict): ?string
{
    if ($outcome['id'] !== null) {
        return null;
    }
    if ($outcome['code'] === 0) {
        return 'the search request never reached the server';
    }
    if ($outcome['code'] === 401 || $outcome['code'] === 403) {
        return "HTTP {$outcome['code']} - your token carries no user/{$resource}.read scope";
    }
    if ($outcome['code'] !== 200) {
        return "the search answered HTTP {$outcome['code']}";
    }
    if ($outcome['entries'] === 0) {
        return "the search succeeded but no {$resource} records exist yet - load demo data";
    }
    if ($strict) {
        return "{$outcome['entries']} {$resource} record(s) found, none of the required kind";
    }

    return "{$outcome['entries']} {$resource} record(s) found but none carried an id";
}

/**
 * True when an Organization carries one of the given OpenEMR type codes.
 *
 * Compared case-insensitively on purpose. FhirOrganizationService declares the constants as
 * `Ins` / `Pay` / `Prov` and matches against them when parsing an incoming write, but the READ
 * side emits lowercase: FhirOrganizationFacilityService and FhirOrganizationProcedureProviderService
 * both add `code => 'prov'` and FhirOrganizationInsuranceService adds `code => 'ins'`. A
 * case-sensitive compare against the constants therefore matches nothing a search returns.
 *
 * @param array<string, mixed> $organization
 * @param array<int, string> $codes
 */
function organizationHasType(array $organization, array $codes): bool
{
    $wanted = array_map('strtolower', $codes);
    foreach (($organization['type'] ?? []) as $type) {
        foreach ((is_array($type) ? ($type['coding'] ?? []) : []) as $coding) {
            if (is_array($coding) && in_array(strtolower((string) ($coding['code'] ?? '')), $wanted, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The first `prov` Organization that is actually a facility.
 *
 * `prov` is emitted by two different services -- FhirOrganizationFacilityService (rows in
 * `facility`) and FhirOrganizationProcedureProviderService (rows in `procedure_providers`) --
 * so the type coding alone cannot tell them apart, and a procedure provider's uuid is not a
 * pc_facility an Appointment can reference. A facility is exposed a second time as a Location
 * off the same uuid; a procedure provider is not. Probing that read is what makes the choice
 * deterministic instead of dependent on search order.
 *
 * @param list<string> $candidates
 */
function firstFacilityBackedOrganization(array $candidates, string $fhirBase, string $access): ?string
{
    foreach ($candidates as $candidate) {
        $probe = fhirCall(
            'GET',
            "{$fhirBase}/Location/" . rawurlencode($candidate),
            $access,
            ['Accept: application/fhir+json']
        );
        if ($probe['code'] === 200) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Substitutes {{placeholders}} at any depth, the server-side twin of the card's fill().
 *
 * @param mixed $value
 * @param array<string, string|null> $context
 * @return mixed
 */
function fillPlaceholders(mixed $value, array $context): mixed
{
    if (is_string($value)) {
        return preg_replace_callback(
            '/{{(\w+)}}/',
            static fn(array $m): string => $context[$m[1]] ?? $m[0],
            $value
        );
    }
    if (is_array($value)) {
        return array_map(static fn(mixed $item): mixed => fillPlaceholders($item, $context), $value);
    }

    return $value;
}

try {
    switch ($action) {
        case 'catalog':
            $catalog = [];
            $tokenScopes = explode(' ', trim((string) ($_SESSION['access_token_scopes'] ?? '')));
            foreach ($writeMatrix as $resource => $definition) {
                $catalog[$resource] = [
                    'status' => $definition['status'] ?? 'supported',
                    'reason' => $definition['reason'] ?? null,
                    // Any spelling that grants create counts: user/ or system/, v1 .write or v2
                    // .c*. Matching only the one v1 string reported "scope missing" for tokens
                    // that could write perfectly well.
                    'hasScope' => ScopeAlgebra::predict($tokenScopes, 'api:fhir', (string) $resource, 'c') === 'allow',
                    'scope' => $definition['scope'],
                    'acl' => $definition['acl'],
                    'verbs' => $definition['verbs'],
                    'readById' => $definition['readById'],
                    'needs' => $definition['needs'],
                    'idKey' => $definition['idKey'] ?? 'uuid',
                    'body' => $definition['body'],
                    'variants' => $definition['variants'] ?? null,
                    'checks' => $definition['checks'] ?? null,
                ];
            }
            echo json_encode([
                'catalog' => $catalog,
                'tokenScopes' => $tokenScopes,
                'fhirBase' => $fhirBase,
            ]);
            break;

        case 'context':
        case 'seed':
            // Each key records how it was resolved so the UI can say *why* a resource is
            // being skipped: a refused read, an empty database, or data of the wrong kind.
            $reasons = [];
            $resolve = static function (
                string $key,
                string $resource,
                string $url,
                ?callable $pick = null,
                bool $strict = false
            ) use (
                $access,
                &$reasons
            ): ?string {
                $outcome = firstBundleEntry($url, $access, $pick, $strict);
                $reason = contextFailureReason($outcome, $resource, $strict);
                if ($reason !== null) {
                    $reasons[$key] = $reason;
                }
                return $outcome['id'];
            };

            $patient = $resolve('patient', 'Patient', "{$fhirBase}/Patient?_count=1");

            // A facility is exposed twice off the same `facility`.`uuid`: as an
            // Organization of type Prov, and as a Location. Resolving it through the
            // Organization is what makes it usable as an Appointment.serviceProvider --
            // FHIR Location also covers patient home addresses, and picking one of those
            // gives a uuid that is not in the facility table.
            $provOrgs = firstBundleEntry(
                "{$fhirBase}/Organization?_count=50",
                $access,
                static fn(array $o): bool => organizationHasType($o, ['Prov']),
                true
            );
            $facility = firstFacilityBackedOrganization($provOrgs['ids'], $fhirBase, $access);
            $facilityReason = contextFailureReason($provOrgs, 'Organization', true);
            if ($facilityReason === null && $facility === null) {
                $facilityReason = count($provOrgs['ids']) . ' provider Organization(s) found, none backed by a facility'
                    . ' (they are procedure providers) - add a facility under Administration > Facilities';
            }
            if ($facilityReason !== null) {
                $reasons['facility'] = $facilityReason;
                $reasons['orgProvider'] = $facilityReason;
            }

            $context = [
                'patient' => $patient,
                'practitioner' => $resolve('practitioner', 'Practitioner', "{$fhirBase}/Practitioner?_count=1"),
                'facility' => $facility,
                'orgProvider' => $facility,
                'orgInsurer' => $resolve(
                    'orgInsurer',
                    'Organization',
                    "{$fhirBase}/Organization?_count=50",
                    static fn(array $o): bool => organizationHasType($o, ['Ins', 'Pay']),
                    true
                ),
                'encounter' => $patient !== null
                    ? $resolve(
                        'encounter',
                        'Encounter',
                        "{$fhirBase}/Encounter?patient=" . rawurlencode($patient) . '&_count=1'
                    )
                    : null,
                'questionnaire' => $resolve('questionnaire', 'Questionnaire', "{$fhirBase}/Questionnaire?_count=1"),
                'unique' => substr(bin2hex(random_bytes(4)), 0, 8),
                // A numeric per-run token, for identifier fields that must be digits (NPI).
                'uniqueDigits' => str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
            ];
            if ($patient === null) {
                $reasons['encounter'] = 'no patient to search encounters for';
            }
            $missing = array_keys(array_filter($context, static fn($v): bool => $v === null));

            if ($action === 'context') {
                echo json_encode(['context' => $context, 'missing' => $missing, 'reasons' => $reasons]);
                break;
            }

            // seed: create what is missing, in the order the fixtures are declared, so a body
            // that references an earlier key ({{patient}} in the encounter) is filled from the
            // resource created a moment ago rather than from a stale read.
            $created = [];
            foreach ($seedFixtures as $key => $fixture) {
                if (($context[$key] ?? null) !== null) {
                    continue;
                }
                $blockedBy = array_values(array_filter(
                    $fixture['needs'] ?? [],
                    static fn(string $need): bool => ($context[$need] ?? null) === null
                ));
                if ($blockedBy !== []) {
                    $created[$key] = [
                        'ok' => false,
                        'resource' => $fixture['resource'],
                        'error' => 'skipped: needs ' . implode(', ', $blockedBy) . ' first',
                    ];
                    continue;
                }

                $payload = json_encode(fillPlaceholders($fixture['body'], $context), JSON_UNESCAPED_SLASHES);
                $response = fhirCall(
                    'POST',
                    "{$fhirBase}/{$fixture['resource']}",
                    $access,
                    ['Accept: application/fhir+json', 'Content-Type: application/fhir+json'],
                    is_string($payload) ? $payload : '{}'
                );
                $decoded = json_decode($response['body'], true);
                $newId = is_array($decoded)
                    ? ($decoded['uuid'] ?? $decoded['euuid'] ?? $decoded['pc_uuid'] ?? $decoded['id'] ?? null)
                    : null;

                if ($response['code'] === 201 && is_string($newId)) {
                    $context[$key] = $newId;
                    if ($key === 'facility') {
                        $context['orgProvider'] = $newId;
                    }
                    unset($reasons[$key], $reasons['orgProvider']);
                    $created[$key] = ['ok' => true, 'resource' => $fixture['resource'], 'id' => $newId];
                } else {
                    $created[$key] = [
                        'ok' => false,
                        'resource' => $fixture['resource'],
                        'code' => $response['code'],
                        'error' => $response['code'] === 401 || $response['code'] === 403
                            ? "HTTP {$response['code']} - your token carries no {$fixture['scope']}"
                            : "HTTP {$response['code']}",
                        'body' => is_array($decoded) ? $decoded : $response['body'],
                    ];
                }
            }

            $missing = array_keys(array_filter($context, static fn($v): bool => $v === null));
            echo json_encode([
                'context' => $context,
                'missing' => $missing,
                'reasons' => $reasons,
                'created' => $created,
            ]);
            break;

        case 'send':
            $raw = file_get_contents('php://input');
            $request = json_decode(is_string($raw) ? $raw : '', true);
            if (!is_array($request)) {
                http_response_code(400);
                echo json_encode(['error' => 'Request must be a JSON object']);
                exit;
            }

            $resource = (string) ($request['resource'] ?? '');
            $verb = strtoupper((string) ($request['verb'] ?? ''));
            $id = (string) ($request['id'] ?? '');
            $body = $request['body'] ?? null;

            if (!isset($writeMatrix[$resource])) {
                http_response_code(400);
                echo json_encode(['error' => "Unknown write resource: {$resource}"]);
                exit;
            }
            if (!in_array($verb, $writeMatrix[$resource]['verbs'], true)) {
                http_response_code(400);
                echo json_encode(['error' => "{$resource} does not support {$verb}"]);
                exit;
            }
            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(['error' => 'body must be a JSON object']);
                exit;
            }
            if ($verb === 'PUT' && $id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'PUT requires the resource id']);
                exit;
            }

            $url = "{$fhirBase}/{$resource}" . ($verb === 'PUT' ? '/' . rawurlencode($id) : '');
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $r = fhirCall($verb, $url, $access, [
                'Accept: application/fhir+json',
                'Content-Type: application/fhir+json',
            ], is_string($payload) ? $payload : '{}');

            $decoded = json_decode($r['body'], true);
            $hint = null;
            if ($r['code'] === 401 || $r['code'] === 403) {
                $hint = "Token is missing {$writeMatrix[$resource]['scope']}, or the user lacks the "
                    . "{$writeMatrix[$resource]['acl']} ACL.";
            } elseif ($r['code'] === 0) {
                $hint = 'The request never reached the server -- check FHIR_SERVER_URL and TLS.';
            }

            echo json_encode([
                'request' => ['method' => $verb, 'url' => $url],
                'code' => $r['code'],
                'location' => $r['location'],
                // The created/updated id. A create answers the OpenEMR insert result, which
                // does not name the id consistently across resources -- Encounter answers
                // `euuid` and Appointment `pc_uuid` -- so the key comes from the matrix.
                // An update answers the FHIR resource itself, keyed `id`.
                'id' => is_array($decoded)
                    ? ($decoded[$writeMatrix[$resource]['idKey'] ?? 'uuid'] ?? $decoded['id'] ?? null)
                    : null,
                'body' => is_array($decoded) ? $decoded : $r['body'],
                'hint' => $hint,
            ]);
            break;

        case 'verify':
            $resource = (string) ($_GET['resource'] ?? '');
            $id = (string) ($_GET['id'] ?? '');
            if (!isset($writeMatrix[$resource]) || $id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'resource and id are required']);
                exit;
            }

            $url = $writeMatrix[$resource]['readById']
                ? "{$fhirBase}/{$resource}/" . rawurlencode($id)
                : "{$fhirBase}/{$resource}?_id=" . rawurlencode($id);
            $r = fhirCall('GET', $url, $access, ['Accept: application/fhir+json']);
            $decoded = json_decode($r['body'], true);

            // A bundle search answers 200 with total 0 when nothing matched, which is a
            // miss rather than a hit.
            $found = $r['code'] === 200;
            if ($found && !$writeMatrix[$resource]['readById']) {
                $found = is_array($decoded) && !empty($decoded['entry']);
            }

            echo json_encode([
                'request' => ['method' => 'GET', 'url' => $url],
                'code' => $r['code'],
                'found' => $found,
                'byIdRoute' => $writeMatrix[$resource]['readById'],
                'body' => is_array($decoded) ? $decoded : $r['body'],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'fhir_write failure', 'detail' => $e->getMessage()]);
}
