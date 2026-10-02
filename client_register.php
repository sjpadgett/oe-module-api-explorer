<?php

/**
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/**
 * Client Register example application for OpenEMR OAuth2 Server.
 *
 * Registers four clients:
 *  - jwt_client_credentials
 *  - confidential_auth_code
 *  - public_auth_code
 *  - smart_ehr_launch
 *
 * Deletes any existing clients with the same name before re‑registering.
 * Works both CLI and browser (HTML) contexts.
 */

use OpenEMR\Auth\JwkService;

session_start();

$_GET['site'] = 'default'; // Set default site for OAuth2
$api_site = $_SESSION['selectedSite'] ?? $_GET['api_site'] ?? null; // config.php settles the default

$ignoreAuth = true;
$sessionAllowWrite = true;
require_once("../../interface/globals.php");
require 'config.php';
require_once __DIR__ . '/src/JwkService.php';

if (php_sapi_name() === 'cli') {
    $url = 'oeApiExplorer?cleanSession=1';
    $url = $GLOBALS['ApiConfig']['REDIRECT_URI'] . '?cleanSession=1';
    out("Under Construction. Need to update to handle the new Site selection feature.\nUse Explorer in browser: {$url}");
    exit;
}

/**
 * @param string $msg
 * @return void
 */
function out(string $msg): void
{
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo nl2br(text($msg)) . "<br/>";
    }
    if (!headers_sent()) {
        @ob_flush();
        @flush();
    }
}

out("Starting keys creation…\n");
$keyDir = __DIR__;
$regen = isset($_GET['regen']) || (php_sapi_name() === 'cli' && in_array('--regen', $argv));
$jwkService = new JwkService($keyDir, $api_site);
$jwkService->ensureKeys($regen);
$jwks = $jwkService->loadJwks();
$jwksUri = $GLOBALS['ApiConfig']['JWKS_LOCATION_URL'];
$inlineJwks = empty($jwksUri) ? $jwks : null;

$clients = [
    'JWT' => [
        'application_type' => 'private',
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['client_credentials'],
        'scope' => SYSTEM_SCOPES,
        'client_name' => "{$api_site} JWT Client Credentials",
        'redirect_uris' => [$GLOBALS['ApiConfig']['REDIRECT_URI']],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
        'jwks_uri' => $jwksUri,
        'jwks' => $inlineJwks,
    ],
    'confidential' => [
        'application_type' => 'private',
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'scope' => LIMITED_SCOPES,
        'client_name' => "{$api_site} Confidential Auth‑Code Client",
        'redirect_uris' => [$GLOBALS['ApiConfig']['REDIRECT_URI']],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
    ],
    'public' => [
        'application_type' => 'public',
        'token_endpoint_auth_method' => 'client_secret_basic',
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'scope' => PUBLIC_SCOPES,
        'client_name' => "{$api_site} Public PKCE Auth‑Code Client",
        'redirect_uris' => [$GLOBALS['ApiConfig']['REDIRECT_URI']],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
    ],
    'smart' => [
        'application_type' => 'private',
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'scope' => SMART_SCOPES,
        'client_name' => "{$api_site} SMART EHR Launch Client",
        'redirect_uris' => [$GLOBALS['ApiConfig']['SMART_REDIRECT_URI']],
        'initiate_login_uri' => $GLOBALS['ApiConfig']['SMART_LAUNCH_URI'],
        'launch_uris' => [$GLOBALS['ApiConfig']['SMART_LAUNCH_URI']],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
        'jwks_uri' => $jwksUri,
        'jwks' => $inlineJwks,
    ]
];

// Ask only for scopes this server publishes. OpenEMR rejects a registration outright for a
// single unknown scope, and the scope lists in config.php track the newest server -- so against
// an older one (or one missing a pending PR) every client would otherwise fail to register.
$publishedScopes = explorerPublishedScopes();
if ($publishedScopes === null) {
    out("⚠ Could not read scopes_supported from the server; registering with the full scope lists.");
} else {
    out("Server publishes " . count($publishedScopes) . " scopes; unpublished ones are left out of each client.");
    $publishedLookup = array_flip($publishedScopes);
    foreach ($clients as $id => $settings) {
        $wanted = preg_split('/\s+/', trim((string) $settings['scope'])) ?: [];
        $kept = array_values(array_filter($wanted, static fn(string $scope): bool => isset($publishedLookup[$scope])));
        $dropped = array_values(array_diff($wanted, $kept));
        if ($dropped !== []) {
            out("→ [{$id}] not published by this server, skipped: " . implode(' ', $dropped));
        }
        $clients[$id]['scope'] = implode(' ', $kept);
    }
}

out("\nStarting client registrations…");
$registrationFailed = false;
foreach ($clients as $id => $settings) {
    $file = __DIR__ . "/clients_keys/client_{$api_site}_{$id}.json";
    $isRemoteClient = stripos($settings['client_name'], 'remote-') !== false;

    out("→ [{$id}] Deleting any existing client named “{$settings['client_name']}”");
    deleteExistingClient($settings['client_name']);

    // Do not leave credentials for a client identifier that was just deleted.
    if (!$isRemoteClient && is_file($file) && !unlink($file)) {
        $registrationFailed = true;
        out("❌ [{$id}] Unable to remove stale credentials file “{$file}”");
        continue;
    }

    out("→ [{$id}] Registering client…");
    try {
        $data = registerClient($settings);
    } catch (Exception $e) {
        $registrationFailed = true;
        out("❌ [{$id}] Registration ERROR: " . $e->getMessage());
        continue;
    }

    if (
        // 'scope' is forced to what was actually requested: the authorize and token calls read
        // it back from this file, so they ask for exactly what the client was registered with.
        file_put_contents($file, json_encode(['scope' => $settings['scope']] + $data + [
            'initiate_login_uri' => $settings['initiate_login_uri'] ?? null,
            'launch_uris' => $settings['launch_uris'] ?? null,
            'jwks_uri' => $settings['jwks_uri'] ?? null,
            'jwks' => $settings['jwks'] ?? null,
            'pem_private' => realpath(__DIR__ . '/private.key'),
            'pem_public' => realpath(__DIR__ . '/public.key')
        ], JSON_PRETTY_PRINT))
    ) {
        out("✓ [{$id}] Saved credentials to “{$file}”");
        if ($isRemoteClient) {
            out("✅ [{$id}] Skipping enable of remote client: {$settings['client_name']}");
        } else {
            sqlStatementNoLog("UPDATE oauth_clients SET is_enabled = 1 WHERE client_name = ?", array($settings['client_name']));
            out("✅ [{$id}] Client enabled in database");
        }
    } else {
        $registrationFailed = true;
        out("❌ [{$id}] Failed writing to “{$file}”");
    }
    out("");
}
out($registrationFailed ? "❌ Registration completed with errors." : "🎉 All clients registered successfully!");

if (php_sapi_name() !== 'cli') {
    $url = $GLOBALS['ApiConfig']['REDIRECT_URI'] . '?cleanSession=1';
    echo text("You can now") . "<a href='{$url}'>" . text(" return to the API Explorer") . '</a>.';
}
exit;

/**
 * The scopes the selected server advertises in .well-known/smart-configuration, or null when
 * they cannot be read. OpenEMR has emitted scopes_supported both as a flat list and wrapped in
 * an extra array, so nesting is flattened.
 *
 * @return list<string>|null
 */
function explorerPublishedScopes(): ?array
{
    $ch = curl_init(rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/') . '/.well-known/smart-configuration');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($config) || !isset($config['scopes_supported']) || !is_array($config['scopes_supported'])) {
        return null;
    }
    $scopes = [];
    array_walk_recursive($config['scopes_supported'], static function ($scope) use (&$scopes): void {
        if (is_string($scope) && $scope !== '') {
            $scopes[] = $scope;
        }
    });

    return $scopes === [] ? null : array_values(array_unique($scopes));
}

/**
 * Registers a single client via dynamic registration.
 *
 * @param array $settings Definition from above.
 * @return array           Decoded JSON response.
 * @throws Exception       On HTTP or JSON errors.
 */
function registerClient(array $settings): array
{
    $payload = [
        'application_type' => $settings['application_type'],
        'token_endpoint_auth_method' => $settings['token_endpoint_auth_method'],
        'client_name' => $settings['client_name'],
        'scope' => $settings['scope'],
        'contacts' => ['admin@example.com'],
    ];

    // Skip secret if using client_secret_post
    if ($settings['token_endpoint_auth_method'] === 'client_secret_post') {
        $payload['client_secret'] = '';
    }

    if (!empty($settings['jwks_uri'])) {
        $payload['jwks_uri'] = $settings['jwks_uri'];
    }
    if (!empty($settings['jwks'])) {
        $payload['jwks'] = $settings['jwks'];
    }
    if (!empty($settings['grant_types'])) {
        $payload['grant_types'] = $settings['grant_types'];
    }
    if (!empty($settings['response_types'])) {
        $payload['response_types'] = $settings['response_types'];
    }
    if (!empty($settings['initiate_login_uri'])) {
        $payload['initiate_login_uri'] = $settings['initiate_login_uri'];
    }
    // Retain launch_uris for compatibility with OpenEMR documentation and
    // registration implementations that accept the array form.
    if (!empty($settings['launch_uris'])) {
        $payload['launch_uris'] = $settings['launch_uris'];
    }
    if (!empty($settings['redirect_uris'])) {
        $payload['redirect_uris'] = $settings['redirect_uris'];
        $payload['post_logout_redirect_uris'] = $settings['post_logout_redirect_uris'] ?? [];
    }

    $ch = curl_init($GLOBALS['ApiConfig']['REGISTER_CLIENT_ENDPOINT']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => 1,
    ]);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception("cURL error: {$err}");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("HTTP {$httpCode} response: {$raw}");
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    return $json;
}

/**
 * @param string $clientName
 * @return void
 */
function deleteExistingClient(string $clientName): void
{
    if (stripos($clientName, 'remote-') !== false) {
        out("Skipping deletion of demo client: {$clientName}");
        return;
    }
    sqlStatementNoLog(
        "DELETE FROM oauth_clients WHERE client_name = ?",
        array($clientName)
    );
}
