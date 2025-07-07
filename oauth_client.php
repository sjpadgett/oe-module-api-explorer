<?php

/**
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// oauth_client.php
session_start();

require_once 'config.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha384;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * Client Credentials Grant via JWT‐Bearer assertion (lcobucci/jwt).
 */
function getClientCredentialsToken($client, $api_site): array
{
    $scope = $client['scope'] ?? SYSTEM_SCOPES;
    $private_key_path = __DIR__ . "/clients_keys/{$api_site}_private.pem";
    // Prepare the JWT config
    if ($_GET['grant'] === 'client_credentials' && $_GET['client'] === 'JWT') {
        $config = Configuration::forAsymmetricSigner(
            new Sha384(),
            InMemory::file($private_key_path), // Private key file
            InMemory::empty()
        );

        // Build and sign the assertion
        $now = new \DateTimeImmutable();
        $jti = bin2hex(random_bytes(16));
        $token = $config->builder()
            ->issuedBy($client['client_id'])            // iss
            ->relatedTo($client['client_id'])           // sub
            ->permittedFor($GLOBALS['ApiConfig']['TOKEN_ENDPOINT'])              // aud
            ->identifiedBy($jti)                        // jti
            ->issuedAt($now)
            ->expiresAt($now->modify('+5 minutes'))
            ->getToken($config->signer(), $config->signingKey());

        $jwt = $token->toString();

        $postData = [
            'grant_type' => 'client_credentials',
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'] ?? '',
            'scope' => $client['scope'],
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt
        ];
    } else {
        // For private clients, we use client_id and secret directly
        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'] ?? '',
            'scope' => $scope,
        ];
    }
    // Request access token
    $ch = curl_init($GLOBALS['ApiConfig']['TOKEN_ENDPOINT']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception("JWT client_credentials error: {$err}");
    }

    $data = json_decode($raw, true) ?: [];

    if (empty($data['access_token'])) {
        // Surface the error_description if present
        $desc = $data['error_description']
            ?? $data['error']
            ?? 'Unknown';
        throw new Exception("Token error: {$desc}");
    }

    $_SESSION['access_token'] = $data['access_token'];
    return $data;
}

/**
 * Authorization Code (with PKCE for public).
 * This function is called when the user clicks the "Authorize" button.
 * It handles both the initial authorization request and the token exchange.
 *
 * @param string $type   The type of client (confidential or public).
 * @param array  $client The client configuration.
 * @return array        The token response.
 * @throws Exception    On cURL error or invalid response.
 */

function getAccessTokenViaAuthCode(string $type, array $client): array
{
    // If we already have tokens, return them
    if (!empty($_SESSION['token_response'])) {
        return $_SESSION['token_response'];
    }
    // Exchange code for tokens
    if (!empty($_GET['code'])) {
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $GLOBALS['ApiConfig']['REDIRECT_URI'],
            'client_id' => $client['client_id'],
        ];
        if ($type === 'confidential') {
            $postData['client_secret'] = $client['client_secret'];
        }
        if ($type === 'public' && !empty($_SESSION['code_verifier'])) {
            $postData['code_verifier'] = $_SESSION['code_verifier'];
        }

        $ch = curl_init($GLOBALS['ApiConfig']['TOKEN_ENDPOINT']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $decoded = json_decode($raw, true) ?: [];
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception("Auth‑code cURL error: {$err}");
        }

        if (!empty($decoded['access_token'])) {
            // Store all tokens
            $_SESSION['token_response'] = $decoded;
            $_SESSION['access_token'] = $decoded['access_token'];
            if (!empty($decoded['refresh_token'])) {
                $_SESSION['refresh_token'] = $decoded['refresh_token'];
            }
        }
        return $decoded;
    }

    // Kick off auth‑code/PKCE
    $scope = $type === 'confidential' ? PRIVATE_SCOPES : PUBLIC_SCOPES;
    $url = $GLOBALS['ApiConfig']['AUTHORIZATION_ENDPOINT']
        . "?response_type=code"
        . "&client_id=" . urlencode($client['client_id'])
        . "&redirect_uri=" . urlencode($GLOBALS['ApiConfig']['REDIRECT_URI'])
        . "&scope=" . urlencode($scope)
        . "&state=explorer";

    if ($type === 'public') {
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['code_verifier'] = $verifier;
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url .= "&code_challenge={$challenge}&code_challenge_method=S256";
    }

    header("Location: {$url}");
    exit;
}

/**
 * Checks if current access token is expired.
 */
function isTokenExpired(): bool
{
    return isset($_SESSION['expires_at']) && time() >= $_SESSION['expires_at'];
}

/**
 * Attempts to refresh access token using stored refresh token.
 */
function refreshAccessToken(array $client): ?string
{
    if (empty($_SESSION['refresh_token'])) {
        return null;
    }

    $postData = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $_SESSION['refresh_token'],
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'] ?? '',
    ];

    $ch = curl_init($GLOBALS['ApiConfig']['TOKEN_ENDPOINT']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (!isset($result['access_token'])) {
        return null;
    }

    $_SESSION['access_token'] = $result['access_token'];
    $_SESSION['refresh_token'] = $result['refresh_token'] ?? $_SESSION['refresh_token'];
    $_SESSION['expires_at'] = isset($result['expires_in']) ? time() + $result['expires_in'] : null;

    return $_SESSION['access_token'];
}

/**
 * @return void
 */
function ensureClientKeysDir(): void
{
    $dir = __DIR__ . '/clients_keys';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException("Failed to create clients_keys directory.");
        }
    }
}
