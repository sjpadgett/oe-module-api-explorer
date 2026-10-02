<?php

/**
 * group_export.php — Bulk FHIR Group $export driver for the API Explorer.
 *
 * AJAX endpoint that SHARES the Explorer's browser session and OAuth token
 * ($_SESSION['access_token'], $_SESSION['selectedSite']). Like scope_resources.php
 * it bootstraps from session + config only — it does NOT pull interface/globals.php,
 * so there's no HTTP_HOST / site-id / CLI problem.
 *
 * Actions (GET ?action=):
 *   list                 GET /fhir/Group  -> provider-panel groups [{id, members, name}]
 *   initiate&groupId=..  GET /fhir/Group/{id}/$export (async) -> {statusUrl}
 *   poll&url=..          GET the status url once -> {done, manifest|pending}
 *
 * @package OpenEMR API
 * @author  Jerry Padgett <sjpadgett@gmail.com>
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once 'config.php';

$access = (string) ($_SESSION['access_token'] ?? '');
if ($access === '') {
    http_response_code(401);
    echo json_encode(['error' => 'No access token in session. Obtain a token in the Explorer first (client_credentials with system/Group.$export and system/*.$bulkdata-status).']);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$action = $_GET['action'] ?? 'list';

/** @return array{code:int,body:string,location:string} */
function fhirGet(string $url, string $access, array $headers, bool $captureLocation = false): array
{
    $ch = curl_init($url);
    $loc = '';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer {$access}"], $headers),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($captureLocation) {
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $h) use (&$loc) {
            if (stripos($h, 'Content-Location:') === 0) {
                $loc = trim(substr($h, strlen('Content-Location:')));
            }
            return strlen($h);
        };
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'location' => $loc];
}

try {
    switch ($action) {
        case 'list':
            $r = fhirGet("{$fhirBase}/Group", $access, ['Accept: application/fhir+json']);
            if ($r['code'] !== 200) {
                http_response_code($r['code'] ?: 502);
                echo json_encode(['error' => "Group list returned HTTP {$r['code']}", 'detail' => substr($r['body'], 0, 600)]);
                exit;
            }
            $bundle = json_decode($r['body'], true);
            $groups = [];
            foreach (($bundle['entry'] ?? []) as $e) {
                $g = $e['resource'] ?? [];
                $groups[] = [
                    'id' => $g['id'] ?? '',
                    'members' => isset($g['member']) ? count($g['member']) : ($g['quantity'] ?? null),
                    'name' => $g['name'] ?? ($g['characteristic'][0]['valueCodeableConcept']['text'] ?? ''),
                ];
            }
            echo json_encode(['groups' => $groups]);
            break;

        case 'initiate':
            $groupId = (string) ($_GET['groupId'] ?? '');
            if ($groupId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'groupId is required']);
                exit;
            }
            $url = "{$fhirBase}/Group/" . rawurlencode($groupId) . '/$export';
            if (!empty($_GET['type'])) {
                $url .= '?_type=' . rawurlencode((string) $_GET['type']);
            }
            $r = fhirGet($url, $access, ['Accept: application/fhir+json', 'Prefer: respond-async'], true);
            if ($r['code'] !== 202 || $r['location'] === '') {
                http_response_code($r['code'] ?: 502);
                echo json_encode([
                    'error' => "Expected 202 with Content-Location, got HTTP {$r['code']}",
                    'detail' => substr($r['body'], 0, 600),
                    'hint' => $r['code'] === 403 || $r['code'] === 401 ? 'Token likely missing system/Group.$export.' : null,
                ]);
                exit;
            }
            echo json_encode(['statusUrl' => $r['location']]);
            break;

        case 'poll':
            $url = (string) ($_GET['url'] ?? '');
            if ($url === '') {
                http_response_code(400);
                echo json_encode(['error' => 'url is required']);
                exit;
            }
            $r = fhirGet($url, $access, ['Accept: application/json']);
            if ($r['code'] === 202) {
                echo json_encode(['done' => false]);
                exit;
            }
            if ($r['code'] === 200) {
                echo json_encode(['done' => true, 'manifest' => json_decode($r['body'], true)]);
                exit;
            }
            http_response_code($r['code'] ?: 502);
            echo json_encode([
                'error' => "Status poll returned HTTP {$r['code']}",
                'detail' => substr($r['body'], 0, 600),
                'hint' => $r['code'] === 403 || $r['code'] === 401 ? 'Token likely missing system/*.$bulkdata-status.' : null,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'group_export failure', 'detail' => $e->getMessage()]);
}
