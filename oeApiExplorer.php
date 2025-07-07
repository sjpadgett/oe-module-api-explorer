<?php

/**
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

session_start();

$ignoreAuth = true;
$sessionAllowWrite = true;
require_once("../../interface/globals.php");
require_once 'config.php';
require_once 'oauth_client.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OpenEMR\Core\Header;

// --- Clean session if requested ---
if (isset($_REQUEST['cleanSession'])) {
    session_unset();
    if (isset($_COOKIE['OpenEMR'])) {
        setcookie('OpenEMR', '', time() - 3600, '/');
        unset($_COOKIE['OpenEMR']);
    }
    header("Location: oeApiExplorer.php");
    exit;
}

// --- Ensure client keys directory exists ---
ensureClientKeysDir();

$api_site = $_GET['api_site'] ?? $_SESSION['selectedSite'] ?? 'localhost';

// --- Handle OAuth callback for Auth Code ---
if (!empty($_GET['code']) && ($_GET['state'] ?? '') === 'explorer') {
    $clientType = $_GET['client'] ?? $_SESSION['client_type'] ?? null;
    $apiType = $_GET['api'] ?? $_SESSION['api_type'] ?? null;
    $resourceType = $_GET['resource'] ?? $_SESSION['resource_type'] ?? null;
    $grantType = $_GET['grant'] ?? $_SESSION['grant_type'] ?? 'authorization_code';

    $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
    if ($clientType && file_exists($clientFile)) {
        $clientData = json_decode(file_get_contents($clientFile), true);
        $tokenResp = getAccessTokenViaAuthCode($clientType, $clientData);
        if (!empty($tokenResp['access_token'])) {
            // Stored in session by helper
        }
    }
    // Redirect back to remove code from URL
    header("Location: oeApiExplorer.php?client={$clientType}&api={$apiType}&resource={$resourceType}&grant=authorization_code");
    exit;
}

// --- Read or persist user selections ---
$clientType = $_GET['client'] ?? $_SESSION['client_type'] ?? null;
$apiType = $_GET['api'] ?? $_SESSION['api_type'] ?? null;
$resourceType = $_GET['resource'] ?? $_SESSION['resource_type'] ?? null;
$grantType = $_GET['grant'] ?? $_SESSION['grant_type'] ?? 'authorization_code';

$_SESSION['client_type'] = $clientType;
$_SESSION['api_type'] = $apiType;
$_SESSION['resource_type'] = $resourceType;
$_SESSION['grant_type'] = $grantType;

// --- Flow description ---
$flowNote = match ($grantType) {
    'authorization_code' => ($clientType === 'public') ? 'PKCE (Public)' : 'Authorization Code',
    'client_credentials' => 'Client Credentials',
    'refresh_token' => 'Refresh Token',
    default => 'Unknown Flow',
};

$errors = [];
$response = null;
if (isset($_SESSION['access_token'])) {
    if (isTokenExpired()) {
        $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
        $clientData = json_decode(file_get_contents($clientFile), true);
        $accessToken = refreshAccessToken($clientData);
    } else {
        $accessToken = $_SESSION['access_token'];
    }
}
$accessToken = $_SESSION['access_token'] ?? null;

// --- On form submit, obtain token & fetch ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $clientType && $apiType && $resourceType) {
    $clientData = [];
    $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
    if (!file_exists($clientFile)) {
        $errors[] = "Missing credentials: {$clientFile}";
    } else {
        $clientData = json_decode(file_get_contents($clientFile), true);
    }

    // 1) Obtain token
    try {
        switch ($grantType) {
            case 'client_credentials':
                $tokenResp = getClientCredentialsToken($clientData, $api_site);
                break;
            case 'authorization_code':
                $tokenResp = getAccessTokenViaAuthCode($clientType, $clientData);
                break;
            case 'refresh_token':
                if (!empty($_SESSION['refresh_token'] ?? '')) {
                    $tokenResp = refreshAccessToken($clientData, $_SESSION['refresh_token']);
                } else {
                    throw new Exception("No refresh token in session; run Auth‑Code first.");
                }
                break;
            default:
                throw new Exception("Unsupported grant: {$grantType}");
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        $tokenResp = [];
    }

    // 2) Extract access_token or show error
    if (!empty($tokenResp['access_token'])) {
        $accessToken = $tokenResp['access_token'];
        $_SESSION['access_token'] = $accessToken;
        $_SESSION['access_token_scopes'] = $tokenResp['scope'] ?? '';
    } else {
        $errors[] = "Token error: " . text(
            $tokenResp['error_description']
                ?? $tokenResp['error']
                ?? 'Unknown'
        );
    }

    // 3) Fetch if token OK
    if ($accessToken && empty($errors)) {
        $baseUrl = ($apiType === 'fhir') ? $GLOBALS['ApiConfig']['FHIR_SERVER_URL'] : $GLOBALS['ApiConfig']['API_SERVER_URL'];
        $resourceUrl = "{$baseUrl}/{$resourceType}";

        try {
            $http = new Client([
                'verify' => false,
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/fhir+json',
                    'Prefer' => 'respond-async',
                ],
            ]);

            $res = $http->get($resourceUrl);
            $statusCode = $res->getStatusCode();

            if ($statusCode === 202) {
                // FHIR Bulk Export: 202 Accepted with Content-Location header
                $contentLocation = $res->getHeader('Content-Location');
                $statusUrl = $contentLocation ? $contentLocation[0] : null;

                if ($statusUrl) {
                    $response = [
                        'status' => 'accepted',
                        'message' => 'Bulk export request accepted',
                        'status_url' => $statusUrl,
                        'poll_after' => $res->getHeader('Retry-After')[0] ?? '1'
                    ];
                } else {
                    $errors[] = "Bulk export accepted but no Content-Location header found";
                }

            } elseif ($statusCode >= 200 && $statusCode < 300) {
                // Standard successful response
                $bodyContent = (string)$res->getBody();

                if (empty($bodyContent)) {
                    $response = ['status' => 'success', 'data' => null];
                } else {
                    $response = json_decode($bodyContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errors[] = "JSON decode error: " . json_last_error_msg() .
                            " (Status: {$statusCode}) | Body: " . substr($bodyContent, 0, 200);
                    }
                }

            } else {
                $errors[] = "HTTP error {$statusCode}: " . $res->getReasonPhrase() . " for URL: {$resourceUrl}";
            }

        } catch (ConnectException $ex) {
            $errors[] = "Connection failed to {$resourceUrl}: " . $ex->getMessage();
        } catch (RequestException $ex) {
            $statusCode = $ex->getResponse() ? $ex->getResponse()->getStatusCode() : 'unknown';
            $responseBody = $ex->getResponse() ? (string)$ex->getResponse()->getBody() : 'no response body';

            $errors[] = "API request failed (Status: {$statusCode}): " . $ex->getMessage() .
                " | URL: {$resourceUrl} | Response: " . substr($responseBody, 0, 200);
        } catch (Exception $ex) {
            $errors[] = "Unexpected error during API call to {$resourceUrl}: " . $ex->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="eng">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenEMR API Explorer</title>
    <?php Header::setupHeader(); ?>
    <script>
        function toggleHelp() {
            const helpDiv = document.getElementById('helpContent');
            helpDiv.style.display = (helpDiv.style.display === 'none') ? 'block' : 'none';
        }
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const siteSelect = document.querySelector('[name="api_site"]');
            const apiSelect = document.querySelector('[name="api"]');
            const clientSelect = document.querySelector('[name="client"]');
            const resourceSelect = document.querySelector('[name="resource"]');
            const grantSelect = document.querySelector('[name="grant"]');
            const form = document.querySelector('form');

            if (!apiSelect || !clientSelect || !resourceSelect || !form || !grantSelect) return;

            // Initialize selects
            function syncGrantClient() {
                const clientVal = clientSelect.value;
                const grantVal = grantSelect.value;

                if (clientVal === 'JWT') {
                    // JWT clients must use client_credentials
                    grantSelect.value = 'client_credentials';
                } else if (clientVal === 'public') {
                    // Public clients must use authorization_code
                    grantSelect.value = 'authorization_code';
                } else if (clientVal === 'confidential') {
                    // Confidential clients default to authorization_code, unless refresh_token explicitly selected
                    if (grantVal !== 'authorization_code' && grantVal !== 'refresh_token') {
                        grantSelect.value = 'authorization_code';
                    }
                }

                // Now sync backwards: if user changed grant type
                const newGrant = grantSelect.value;
                if (newGrant === 'client_credentials') {
                    if (clientVal !== 'confidential' && clientVal !== 'JWT') {
                        clientSelect.value = 'confidential';
                    }
                } else if (newGrant === 'authorization_code') {
                    if (clientVal === 'JWT') {
                        clientSelect.value = 'confidential'; // fallback
                    }
                } else if (newGrant === 'refresh_token') {
                    if (clientVal === 'public' || clientVal === 'JWT') {
                        clientSelect.value = 'confidential';
                    }
                }
            }

            function updateResources() {
                const api = apiSelect.value;
                const client = clientSelect.value;

                syncGrantClient();

                fetch('scope_resources.php?client_type=' + encodeURIComponent(client) +
                    '&grant_type=' + encodeURIComponent(grantSelect.value) +
                    '&api_site=' + encodeURIComponent(siteSelect.value))
                .then(res => res.json()).then(data => {
                    let list = data[api] || [];
                    if (api === 'fhir' && client === 'public') {
                        list = list.concat(data.public || []);
                    }
                    // De-dupe and sort
                    list = [...new Set(list)].sort();
                    resourceSelect.innerHTML = '';
                    list.forEach(res => {
                        const opt = document.createElement('option');
                        if (res == '$export') {
                            opt.textContent = 'Export */$export';
                        } else {
                            opt.textContent = res;
                        }
                        opt.value = res;
                        if (res === "<?= $resourceType ?>") opt.selected = true;
                        resourceSelect.appendChild(opt);
                    });
                });
                resourceSelect.addEventListener('change', () => form.submit());
            }

            // Init and bind
            updateResources();
            apiSelect.addEventListener('change', updateResources);
            clientSelect.addEventListener('change', updateResources);
            grantSelect.addEventListener('change', updateResources);
            siteSelect.addEventListener('change', updateResources);
        });
    </script>
</head>
<body>
    <div class="container mt-4">
        <h2 class="mb-4">OpenEMR API Explorer</h2>
        <div class="card mb-3">
            <div class="card-header" style="cursor:pointer;" onclick="toggleHelp()">
                <strong>📘 Help Read Me</strong>
            </div>
        </div>
        <div id="helpContent" class="card-body py-0" style="display:none;">
            <?php
            include_once 'readme.php';
            ?>
            <div class="card-header" style="cursor:pointer;" onclick="toggleHelp()">
                <strong>📘 Close Help Paned</strong>
            </div>
        </div>
        <div class="alert alert-info">
            Using Flow: <strong><?= text($flowNote) ?></strong>
            &nbsp;|&nbsp; Refresh Token: <strong><?= text(($_SESSION['refresh_token'] ?? '') ? 'Available' : 'None') ?></strong>
        </div>
        <hr />
        <form method="get" class="mb-2">
            <div class="mb-2 form-inline">
                <label for="api_site">API Site:</label>
                <select class="form-control ml-1" name="api_site" id="api_site">
                    <?php foreach ($apiSites as $label => $url) : ?>
                        <option value="<?= attr($label) ?>" <?= ($label === $_SESSION['selectedSite']) ? 'selected' : '' ?>>
                            <?= text($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="btn-group">
                    <a href="client_register.php?regen=1&api_site=<?php echo($_SESSION['selectedSite']); ?>" class="btn  btn-outline-success">Register Clients</a>
                    <button type="submit" name="cleanSession" value="1" class="btn  btn-danger">Clear Session</button>
                    <button type="submit" name="fetch_action" class="btn  btn-primary" value="true">Fetch</button>
                </div>
            </div>
            <hr />
            <div class="form-inline">
                <!-- Client Type -->
                <div class="form-group mr-2">
                    <label class="mr-1">Client</label>
                    <select name="client" class="form-control" required>
                        <option value="confidential" <?= $clientType === 'confidential' ? 'selected' : '' ?>>Confidential</option>
                        <option value="public" <?= $clientType === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="JWT" <?= $clientType === 'JWT' ? 'selected' : '' ?>>JWT</option>
                    </select>
                </div>
                <!-- Grant Type -->
                <div class="form-group mr-2">
                    <label class="mr-1">Grant</label>
                    <select name="grant" class="form-control" required>
                        <option value="authorization_code" <?= $grantType === 'authorization_code' ? 'selected' : '' ?>>Authorization Code</option>
                        <option value="client_credentials" <?= $grantType === 'client_credentials' ? 'selected' : '' ?>>Client Credentials</option>
                        <!--<option value="refresh_token" <?php /*= $grantType === 'refresh_token' ? 'selected' : '' */ ?>>Refresh Token</option>-->
                    </select>
                </div>
                <!-- API Type -->
                <div class="form-group mr-2">
                    <label class="mr-1">API</label>
                    <select name="api" class="form-control" required>
                        <option value="fhir" <?= $apiType === 'fhir' ? 'selected' : '' ?>>FHIR</option>
                        <option value="standard" <?= $apiType === 'standard' ? 'selected' : '' ?>>Standard</option>
                    </select>
                </div>
                <!-- Resource -->
                <div class="form-group mr-2">
                    <label class="mr-1">Resource <i>(auto fetch)</i></label>
                    <select name="resource" class="form-control" required>
                        <!-- JS will dynamically populate options -->
                    </select>
                </div>
                <button type="submit" name="fetch_action" class="btn btn-primary mx-0" value="true">Fetch</button>
        </form>
    </div>

    <hr />
    <?php if ($accessToken) : ?>
        <div class="alert alert-secondary"><strong>Access Token:</strong>
            <pre class="small"><?= text($accessToken) ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($errors) : ?>
        <div class="alert alert-danger">
            <ul><?php foreach ($errors as $err) : ?>
                    <li><?= text($err) ?></li>
                <?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($response) : ?>
        <div class="card mt-4">
            <div class="card-header"><strong>API Response</strong></div>
            <div class="card-body">
                <pre><?= json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?></pre>
            </div>
        </div>
    <?php endif; ?>
    </div>
</body>
</html>
