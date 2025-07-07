<?php

/**
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/**
 * Client Register example application for OpenEMR OAuth2 Server.
 *
 * Registers three clients:
 *  - jwt_client_credentials
 *  - confidential_auth_code
 *  - public_auth_code
 *
 * Deletes any existing clients with the same name before re‑registering.
 * Works both CLI and browser (HTML) contexts.
 */

session_start();

$_GET['site'] = 'default'; // Set default site for OAuth2
$api_site = $_SESSION['selectedSite'] ?? $_GET['api_site'] ?? 'localhost';

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
$jwkService = new \OpenEMR\Auth\JwkService($keyDir, $api_site ?? $_SESSION['selectedSite'] ?? 'localhost');
$jwkService->ensureKeys($regen);
$jwks = $jwkService->loadJwks();

$clients = [
    'JWT' => [
        'application_type' => 'private',
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['client_credentials'],
        'scope' => SYSTEM_SCOPES,
        'client_name' => "{$api_site} JWT Client Credentials",
        'redirect_uris' => [$GLOBALS['ApiConfig']['REDIRECT_URI']],
        'post_logout_redirect_uris' => [$GLOBALS['ApiConfig']['LOGOUT_REDIRECT_URI']],
        'jwks_uri' => $GLOBALS['ApiConfig']['JWKS_LOCATION_URL'],
        'jwks' => $jwks,
    ],
    'confidential' => [
        'application_type' => 'private',
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'scope' => PRIVATE_SCOPES,
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
    ]
];

out("\nStarting client registrations…");
foreach ($clients as $id => $settings) {
    out("→ [{$id}] Deleting any existing client named “{$settings['client_name']}”");
    deleteExistingClient($settings['client_name']);

    out("→ [{$id}] Registering client…");
    try {
        $data = registerClient($settings);
    } catch (Exception $e) {
        out("❌ [{$id}] Registration ERROR: " . $e->getMessage());
        continue;
    }

    $file = __DIR__ . "/clients_keys/client_{$api_site}_{$id}.json";

    if (
        file_put_contents($file, json_encode($data + [
            'jwks_uri' => $settings['jwks_uri'] ?? null,
            'jwks' => $settings['jwks'] ?? null,
            'pem_private' => realpath(__DIR__ . '/private.key'),
            'pem_public' => realpath(__DIR__ . '/public.key')
        ], JSON_PRETTY_PRINT))
    ) {
        out("✓ [{$id}] Saved credentials to “{$file}”");
        if (stripos($settings['client_name'], 'remote-') !== false) {
            out("✅ [{$id}] Skipping enable of remote client: {$settings['client_name']}");
        } else {
            sqlStatementNoLog("UPDATE oauth_clients SET is_enabled = 1 WHERE client_name = ?", array($settings['client_name']));
            out("✅ [{$id}] Client enabled in database");
        }
    } else {
        out("❌ [{$id}] Failed writing to “{$file}”");
    }
    out("");
}
out("🎉 All done!");

if (php_sapi_name() !== 'cli') {
    $url = $GLOBALS['ApiConfig']['REDIRECT_URI'] . '?cleanSession=1';
    echo text("You can now") . "<a href='{$url}'>" . text(" return to the API Explorer") . '</a>.';
}
exit;

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
