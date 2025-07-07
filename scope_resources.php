<?php

// scope_resources.php
session_start();

if ($_GET['api_site']) {
    $api_site = $_GET['api_site'] ?? $_SESSION['selectedSite'] ?? 'localhost';
    $_SESSION['selectedSite'] = $api_site;
}

require_once 'config.php';

$scopes = null;
$fhir = [];
$standard = [];
$public = [];

// Fallback: use known scopes if not set
if (!$scopes) {
    $scopes = '';
    if ($_GET['grant_type'] === 'client_credentials') {
        $scopes = SYSTEM_SCOPES;
    } elseif ($_GET['client_type'] === 'public') {
        $scopes = PUBLIC_SCOPES;
    } else {
        $scopes = PRIVATE_SCOPES;
    }
}

// Detect FHIR/standard from granted scopes
preg_match_all('/(?:user|system)\/([A-Za-z]+)\.read/', $scopes, $matchesRead);
foreach ($matchesRead[1] as $res) {
    if (ctype_upper($res[0])) {
        $fhir[] = $res;
    } else {
        $standard[] = $res;
    }
}

// If system grant, include $export scopes as FHIR resources
if ($_GET['grant_type'] === 'client_credentials') {
    preg_match_all('/system\/([A-Za-z*]+)\.\$export/', SYSTEM_SCOPES, $matchesExport);
    foreach ($matchesExport[1] as $res) {
        $fhir[] = ($res === '*') ? '$export' : (($res == 'Group') ? "$res/9edd6194-ed11-4507-8fc5-139a779974b9/\$export" : "$res/\$export");
    }
}

// Public
preg_match_all('/patient\/([A-Za-z]+)\.read/', PUBLIC_SCOPES, $matchesPublic);
$public = array_unique($matchesPublic[1]);

echo json_encode([
    'fhir' => array_values(array_unique($fhir)),
    'standard' => array_values(array_unique($standard)),
    'public' => $public,
]);
