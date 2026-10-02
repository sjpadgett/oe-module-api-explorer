<?php

/**
 * OAuth and SMART authorization helpers for OpenEMR API Explorer.
 *
 * @package   OpenEMR API
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025-2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once 'config.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha384;

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array<string, mixed> */
function discoverSmartConfiguration(string $issuer): array
{
    $issuer = rtrim($issuer, '/');
    $url = $issuer . '/.well-known/smart-configuration';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("SMART discovery failed: {$error}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("SMART discovery returned HTTP {$status}: {$raw}");
    }

    $configuration = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) {
        throw new RuntimeException('SMART discovery response was not an object.');
    }
    foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
        if (empty($configuration[$required]) || !is_string($configuration[$required])) {
            throw new RuntimeException("SMART discovery omitted {$required}.");
        }
    }
    return $configuration;
}

/** @return array<string, mixed> */
function requestToken(string $tokenEndpoint, array $postData): array
{
    $ch = curl_init($tokenEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Token request failed: {$error}");
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Token response was not an object.');
    }
    if ($status < 200 || $status >= 300 || empty($decoded['access_token'])) {
        $description = $decoded['error_description'] ?? $decoded['error'] ?? $raw;
        throw new RuntimeException("Token endpoint returned HTTP {$status}: {$description}");
    }
    return $decoded;
}

/** @return array<string, mixed> */
function getClientCredentialsToken(array $client, string $apiSite): array
{
    $privateKeyPath = __DIR__ . "/clients_keys/{$apiSite}_private.pem";
    $config = Configuration::forAsymmetricSigner(
        new Sha384(),
        InMemory::file($privateKeyPath),
        InMemory::empty()
    );
    $now = new DateTimeImmutable();
    $assertion = $config->builder()
        ->issuedBy((string)$client['client_id'])
        ->relatedTo((string)$client['client_id'])
        ->permittedFor($GLOBALS['ApiConfig']['TOKEN_ENDPOINT'])
        ->identifiedBy(bin2hex(random_bytes(16)))
        ->issuedAt($now)
        ->expiresAt($now->modify('+5 minutes'))
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    $data = requestToken($GLOBALS['ApiConfig']['TOKEN_ENDPOINT'], [
        'grant_type' => 'client_credentials',
        'client_id' => $client['client_id'],
        'scope' => $client['scope'] ?? SYSTEM_SCOPES,
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
    ]);
    storeTokenResponse($data);
    return $data;
}

/** @param array<string, mixed> $tokenResponse */
function storeTokenResponse(array $tokenResponse): void
{
    $_SESSION['token_response'] = $tokenResponse;
    $_SESSION['access_token'] = $tokenResponse['access_token'] ?? null;
    $_SESSION['refresh_token'] = $tokenResponse['refresh_token'] ?? null;
    $_SESSION['expires_at'] = isset($tokenResponse['expires_in'])
        ? time() + (int)$tokenResponse['expires_in']
        : null;
}

/**
 * Starts or completes authorization-code flow. EHR launch is selected when the
 * session contains smart_launch and adds iss/aud/launch to the request.
 *
 * @return array<string, mixed>
 */
function getAccessTokenViaAuthCode(string $type, array $client): array
{
    if (!empty($_SESSION['token_response'])) {
        return $_SESSION['token_response'];
    }

    $isSmartLaunch = $type === 'smart' && !empty($_SESSION['smart_launch']);
    $redirectUri = $isSmartLaunch
        ? $GLOBALS['ApiConfig']['SMART_REDIRECT_URI']
        : $GLOBALS['ApiConfig']['REDIRECT_URI'];
    $tokenEndpoint = $isSmartLaunch
        ? (string)($_SESSION['smart_configuration']['token_endpoint'] ?? '')
        : $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'];

    if (!empty($_GET['code'])) {
        $expectedState = $_SESSION['oauth_state'] ?? '';
        $receivedState = $_GET['state'] ?? '';
        if (!is_string($receivedState) || !hash_equals((string)$expectedState, $receivedState)) {
            throw new RuntimeException('OAuth state validation failed.');
        }
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => (string)$_GET['code'],
            'redirect_uri' => $redirectUri,
            'client_id' => $client['client_id'],
        ];
        if (in_array($type, ['confidential', 'smart'], true) && !empty($client['client_secret'])) {
            $postData['client_secret'] = $client['client_secret'];
        }
        if (!empty($_SESSION['code_verifier'])) {
            $postData['code_verifier'] = $_SESSION['code_verifier'];
        }
        $decoded = requestToken($tokenEndpoint, $postData);
        storeTokenResponse($decoded);
        return $decoded;
    }

    $authorizationEndpoint = $isSmartLaunch
        ? (string)($_SESSION['smart_configuration']['authorization_endpoint'] ?? '')
        : $GLOBALS['ApiConfig']['AUTHORIZATION_ENDPOINT'];
    // Ask for what the client was registered with. Register Clients leaves out scopes the
    // server does not publish, so the constants can be wider than the registration -- and a
    // request beyond the registration is refused.
    $scope = !empty($client['scope']) && is_string($client['scope'])
        ? $client['scope']
        : ($type === 'smart'
            ? SMART_SCOPES
            : ($type === 'confidential' ? LIMITED_SCOPES : PUBLIC_SCOPES));

    $state = base64UrlEncode(random_bytes(32));
    $verifier = base64UrlEncode(random_bytes(64));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['code_verifier'] = $verifier;
    $_SESSION['client_type'] = $type;

    $params = [
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => $redirectUri,
        'scope' => $scope,
        'state' => $state,
        'code_challenge' => base64UrlEncode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    if ($isSmartLaunch) {
        $params['aud'] = (string)$_SESSION['smart_issuer'];
        $params['launch'] = (string)$_SESSION['smart_launch'];
    }

    header('Location: ' . $authorizationEndpoint . '?' . http_build_query($params));
    exit;
}

function isTokenExpired(): bool
{
    return isset($_SESSION['expires_at']) && is_int($_SESSION['expires_at']) && time() >= $_SESSION['expires_at'];
}

function refreshAccessToken(array $client): ?string
{
    if (empty($_SESSION['refresh_token'])) {
        return null;
    }
    $tokenEndpoint = (string)($_SESSION['smart_configuration']['token_endpoint']
        ?? $GLOBALS['ApiConfig']['TOKEN_ENDPOINT']);
    $postData = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $_SESSION['refresh_token'],
        'client_id' => $client['client_id'],
    ];
    if (!empty($client['client_secret'])) {
        $postData['client_secret'] = $client['client_secret'];
    }
    $result = requestToken($tokenEndpoint, $postData);
    storeTokenResponse($result);
    return (string)$result['access_token'];
}

function ensureClientKeysDir(): void
{
    $dir = __DIR__ . '/clients_keys';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create clients_keys directory.');
    }
}
