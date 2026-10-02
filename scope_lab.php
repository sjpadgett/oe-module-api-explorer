<?php

/**
 * scope_lab.php — JSON endpoint behind the Scope Lab panel.
 *
 * Actions (?action=):
 *   state     GET   grants, profiles instantiated for ?grant=&context= with preflight, discovery
 *                   summary, and every stored run (tokens are never included)
 *   discover  POST  read .well-known/smart-configuration for the selected site
 *   run       POST  {profile, grant, context, custom?, options?, password?}
 *                   non-interactive grants: register -> token -> analyse, returns the run
 *                   auth-code grants: register, then returns {authorize_url} to navigate to
 *   analyze   POST  {id, options?} analyse a run whose token came back through the callback
 *   adopt     POST  {id} hand a run's access token to the rest of the Explorer
 *   clear     POST  {deleteClients?} forget runs; optionally delete this site's lab clients
 *   export    GET   every run as a JSON download
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Any notice emitted while bootstrapping (config.php's second session_start(), a deprecation
// from globals) would otherwise land ahead of the JSON and break the panel's parse.
ob_start();
session_start();
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once '../../interface/globals.php';
require_once 'config.php';
require_once __DIR__ . '/scope_lab_lib.php';

use OpenEMR\ApiExplorer\ScopeLab\ScopeAlgebra;

$action = (string) ($_GET['action'] ?? 'state');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** @param array<string, mixed> $payload */
function labJson(int $status, array $payload): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** @return array<string, mixed> */
function labBody(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// Mutating actions are POST-only and must carry the Explorer's per-session token, so a
// page on another origin cannot drive client registration through a logged-in browser.
if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_EXPLORER_CSRF'] ?? '';
    if (!is_string($csrf) || empty($_SESSION['smart_qr_csrf']) || !hash_equals((string) $_SESSION['smart_qr_csrf'], $csrf)) {
        labJson(403, ['error' => 'Missing or invalid CSRF token — reload the Explorer.']);
    }
}

try {
    switch ($action) {
        case 'state':
            $grant = (string) ($_GET['grant'] ?? 'client_credentials');
            $grant = isset(LAB_GRANTS[$grant]) ? $grant : 'client_credentials';
            $context = (string) ($_GET['context'] ?? '');
            if (!in_array($context, LAB_GRANTS[$grant]['contexts'], true)) {
                $context = LAB_GRANTS[$grant]['contexts'][0];
            }
            $published = labPublished();
            $profiles = [];
            foreach (array_keys(labConfig()['profiles']) as $id) {
                $profile = labInstantiate((string) $id, $context);
                $profiles[] = [
                    'id' => $id,
                    'label' => $profile['label'],
                    'group' => $profile['group'],
                    'note' => $profile['note'],
                    'applicable' => in_array($context, $profile['contexts'], true),
                    'registered' => $profile['registered'],
                    'requested' => $profile['requested'],
                    'preflight' => labPreflight($profile['registered'], $profile['requested'], $published, $profile['expect']),
                ];
            }
            $discovery = $_SESSION['scope_lab']['discovery'][labSite()] ?? null;
            labJson(200, [
                'site' => labSite(),
                'grant' => $grant,
                'context' => $context,
                'grants' => LAB_GRANTS,
                'base' => labConfig()['base'],
                'profiles' => $profiles,
                'discovery' => is_array($discovery) ? $discovery : null,
                'runs' => array_values(array_filter(
                    (array) ($_SESSION['scope_lab']['runs'] ?? []),
                    static fn($run): bool => is_array($run) && ($run['site'] ?? null) === labSite()
                )),
                'callback' => labCallbackUri(),
            ]);

        case 'discover':
            $url = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/') . '/.well-known/smart-configuration';
            $response = labHttp('GET', $url, ['Accept' => 'application/json']);
            if ($response['status'] !== 200 || !is_array($response['json'])) {
                labJson(502, ['error' => "Discovery failed ({$response['status']}): " . labErrorText($response), 'url' => $url]);
            }
            $config = $response['json'];
            $config['scopes_supported'] = (array) ($config['scopes_supported'] ?? []);
            // OpenEMR's SMARTConfigurationController wraps the list in an extra array
            // ("scopes_supported" => [$scopesSupported]), so the JSON arrives as [[...]].
            // Flatten one level of nesting so both shapes read the same.
            $scopes = [];
            array_walk_recursive($config['scopes_supported'], static function ($scope) use (&$scopes): void {
                if (is_string($scope)) {
                    $scopes[] = $scope;
                }
            });
            $scopes = array_values(array_unique($scopes));
            $summary = ['v1' => 0, 'v2' => 0, 'write' => 0, 'granular' => 0, 'operations' => 0, 'invalid' => [], 'contexts' => []];
            foreach ($scopes as $scope) {
                $parsed = ScopeAlgebra::parse($scope);
                if ($parsed['kind'] === 'operation') {
                    $summary['operations']++;
                    continue;
                }
                if ($parsed['kind'] !== 'resource') {
                    continue;
                }
                if (!$parsed['valid']) {
                    $summary['invalid'][] = $scope;
                    continue;
                }
                $summary[(string) $parsed['version']]++;
                $summary['contexts'][(string) $parsed['context']] = ($summary['contexts'][(string) $parsed['context']] ?? 0) + 1;
                if (array_intersect(['c', 'u', 'd'], $parsed['perms']) !== []) {
                    $summary['write']++;
                }
                if ($parsed['constraint'] !== null) {
                    $summary['granular']++;
                }
            }
            $_SESSION['scope_lab']['discovery'][labSite()] = [
                'url' => $url,
                'fetched' => date('c'),
                'scopes_supported' => $scopes,
                'capabilities' => array_values((array) ($config['capabilities'] ?? [])),
                'grant_types_supported' => array_values((array) ($config['grant_types_supported'] ?? [])),
                'introspection_endpoint' => $config['introspection_endpoint'] ?? null,
                'summary' => $summary,
            ];
            labJson(200, ['discovery' => $_SESSION['scope_lab']['discovery'][labSite()]]);

        case 'run':
            $body = labBody();
            $grant = (string) ($body['grant'] ?? '');
            $context = (string) ($body['context'] ?? '');
            $profileId = (string) ($body['profile'] ?? '');
            $custom = is_array($body['custom'] ?? null) ? $body['custom'] : null;
            $options = is_array($body['options'] ?? null) ? $body['options'] : [];

            $run = labNewRun($profileId, $grant, $context, $custom);
            $run['registration'] = labRegister($profileId, $grant, $run['registered']);
            if (!$run['registration']['ok']) {
                $run['verdicts'] = labVerdicts($run);
                labStoreRun($run);
                labJson(200, ['run' => $_SESSION['scope_lab']['runs'][$run['id']]]);
            }
            $client = labLoadClient($profileId, $grant);

            if (LAB_GRANTS[$grant]['interactive']) {
                $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
                $_SESSION['scope_lab']['pending'][$state] = [
                    'run' => $run['id'],
                    'verifier' => $verifier,
                    'options' => $options,
                ];
                labStoreRun($run);
                $params = [
                    'response_type' => 'code',
                    'client_id' => (string) $client['client_id'],
                    'redirect_uri' => labCallbackUri(),
                    'scope' => implode(' ', $run['requested']),
                    'state' => $state,
                    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                    'code_challenge_method' => 'S256',
                ];
                labJson(200, [
                    'run' => $_SESSION['scope_lab']['runs'][$run['id']],
                    'authorize_url' => $GLOBALS['ApiConfig']['AUTHORIZATION_ENDPOINT'] . '?' . http_build_query($params),
                ]);
            }

            $fields = labClientAuth($client, $grant) + ['scope' => implode(' ', $run['requested'])];
            if ($grant === 'client_credentials') {
                $fields['grant_type'] = 'client_credentials';
            } else {
                $password = is_array($body['password'] ?? null) ? $body['password'] : [];
                $fields['grant_type'] = 'password';
                $fields['user_role'] = $context === 'patient' ? 'patient' : 'users';
                $fields['username'] = (string) ($password['username'] ?? '');
                $fields['password'] = (string) ($password['password'] ?? '');
                if ($context === 'patient') {
                    $fields['email'] = (string) ($password['email'] ?? '');
                }
                if ($fields['username'] === '' || $fields['password'] === '') {
                    throw new InvalidArgumentException('Password grant needs a username and password.');
                }
            }
            $parsed = labTokenResult(labPostForm((string) $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'], $fields), $run['requested']);
            $run['token'] = $parsed['block'];
            $refresh = $parsed['refresh'];
            if ($parsed['access'] !== null) {
                $analysis = labAnalyze($run, ['access' => $parsed['access'], 'refresh' => $refresh], $options);
                $run = $analysis['run'];
                $refresh = $analysis['refresh'];
                $run['analyzed'] = true;
            }
            $run['verdicts'] = labVerdicts($run);
            labStoreRun($run, $parsed['access'], $refresh);
            labJson(200, ['run' => $_SESSION['scope_lab']['runs'][$run['id']]]);

        case 'analyze':
            $body = labBody();
            $id = (string) ($body['id'] ?? '');
            $run = $_SESSION['scope_lab']['runs'][$id] ?? null;
            $tokens = $_SESSION['scope_lab']['tokens'][$id] ?? null;
            if (!is_array($run) || !is_array($tokens) || empty($tokens['access'])) {
                labJson(404, ['error' => 'No token held for that run.']);
            }
            $options = is_array($body['options'] ?? null) ? $body['options'] : [];
            $analysis = labAnalyze($run, ['access' => (string) $tokens['access'], 'refresh' => $tokens['refresh'] ?? null], $options);
            $run = $analysis['run'];
            $run['analyzed'] = true;
            unset($run['pendingOptions']);
            labStoreRun($run, (string) $tokens['access'], $analysis['refresh']);
            labJson(200, ['run' => $_SESSION['scope_lab']['runs'][$run['id']]]);

        case 'adopt':
            $body = labBody();
            $id = (string) ($body['id'] ?? '');
            $run = $_SESSION['scope_lab']['runs'][$id] ?? null;
            $access = $_SESSION['scope_lab']['tokens'][$id]['access'] ?? null;
            if (!is_array($run) || !is_string($access) || $access === '') {
                labJson(404, ['error' => 'No token held for that run.']);
            }
            $scope = implode(' ', (array) $run['token']['granted']);
            $_SESSION['access_token'] = $access;
            $_SESSION['access_token_scopes'] = $scope;
            $_SESSION['token_response'] = ['access_token' => $access, 'token_type' => 'Bearer', 'scope' => $scope];
            unset($_SESSION['refresh_token'], $_SESSION['expires_at']);
            labJson(200, ['adopted' => $id, 'scope' => $scope]);

        case 'clear':
            $body = labBody();
            $site = labSite();
            foreach (array_keys((array) ($_SESSION['scope_lab']['runs'] ?? [])) as $id) {
                if (($_SESSION['scope_lab']['runs'][$id]['site'] ?? null) === $site) {
                    unset($_SESSION['scope_lab']['runs'][$id], $_SESSION['scope_lab']['tokens'][$id]);
                }
            }
            $_SESSION['scope_lab']['pending'] = [];
            $deleted = null;
            if (!empty($body['deleteClients'])) {
                $files = glob(__DIR__ . '/clients_keys/scopelab_' . labSlug($site) . '_*.json') ?: [];
                foreach ($files as $file) {
                    @unlink($file);
                }
                $deleted = ['files' => count($files), 'clients' => null];
                if (!labIsRemote()) {
                    $row = sqlQuery('SELECT COUNT(*) AS n FROM oauth_clients WHERE client_name LIKE ?', [$site . ' ScopeLab %']);
                    sqlStatementNoLog('DELETE FROM oauth_clients WHERE client_name LIKE ?', [$site . ' ScopeLab %']);
                    $deleted['clients'] = (int) ($row['n'] ?? 0);
                }
            }
            labJson(200, ['cleared' => true, 'deleted' => $deleted]);

        case 'export':
            header('Content-Disposition: attachment; filename="scope-lab-' . labSlug(labSite()) . '-' . date('Ymd-His') . '.json"');
            labJson(200, [
                'exported' => date('c'),
                'site' => labSite(),
                'fhir' => $GLOBALS['ApiConfig']['FHIR_SERVER_URL'],
                'discovery' => $_SESSION['scope_lab']['discovery'][labSite()] ?? null,
                'runs' => array_values(array_filter(
                    (array) ($_SESSION['scope_lab']['runs'] ?? []),
                    static fn($run): bool => is_array($run) && ($run['site'] ?? null) === labSite()
                )),
            ]);

        default:
            labJson(400, ['error' => "Unknown action {$action}"]);
    }
} catch (Throwable $e) {
    labJson(500, ['error' => $e->getMessage()]);
}
