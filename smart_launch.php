<?php

/**
 * SMART EHR launch entry point for OpenEMR API Explorer.
 *
 * @package   OpenEMR API
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

session_start();
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once '../../interface/globals.php';
require_once 'config.php';

$issuer = filter_input(INPUT_GET, 'iss', FILTER_UNSAFE_RAW);
$launch = filter_input(INPUT_GET, 'launch', FILTER_UNSAFE_RAW);
if (!is_string($issuer) || $issuer === '' || filter_var($issuer, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    die(text('Missing or invalid SMART issuer.'));
}
if (!is_string($launch) || $launch === '') {
    http_response_code(400);
    die(text('Missing SMART launch parameter.'));
}

$normalizedIssuer = rtrim($issuer, '/');
$issuerSite = null;
foreach ($apiSites as $siteName => $siteBasePath) {
    $normalizedSiteBasePath = rtrim($siteBasePath, '/');
    if (
        $normalizedIssuer === $normalizedSiteBasePath
        || $normalizedIssuer === $normalizedSiteBasePath . '/apis/' . $explorerOpenemrSite . '/fhir'
    ) {
        $issuerSite = $siteName;
        break;
    }
}
if ($issuerSite === null) {
    http_response_code(400);
    die(text('SMART issuer is not configured in API Explorer.'));
}

// The EHR launch issuer is authoritative. Reload once when an older Explorer
// session points to a different OpenEMR target so the matching client file,
// authorization endpoint, token endpoint, and FHIR endpoint are selected.
if (($selectedSite ?? null) !== $issuerSite) {
    $_SESSION['selectedSite'] = $issuerSite;
    header('Location: smart_launch.php?' . http_build_query([
        'iss' => $issuer,
        'launch' => $launch,
    ]));
    exit;
}

require_once 'oauth_client.php';

// Start a fresh OAuth transaction without discarding the OpenEMR application session.
unset(
    $_SESSION['token_response'],
    $_SESSION['access_token'],
    $_SESSION['refresh_token'],
    $_SESSION['expires_at'],
    $_SESSION['oauth_state'],
    $_SESSION['code_verifier']
);

try {
    $_SESSION['smart_issuer'] = rtrim($issuer, '/');
    $_SESSION['smart_launch'] = $launch;
    $_SESSION['smart_configuration'] = discoverSmartConfiguration($_SESSION['smart_issuer']);
    $_SESSION['client_type'] = 'smart';
    $_SESSION['grant_type'] = 'authorization_code';
    $_SESSION['api_type'] = 'fhir';
    header('Location: oeApiExplorer.php?smart_authorize=1');
    exit;
} catch (Throwable $exception) {
    http_response_code(400);
    echo '<h3>' . text('SMART launch failed') . '</h3>';
    echo '<pre>' . text($exception->getMessage()) . '</pre>';
}
