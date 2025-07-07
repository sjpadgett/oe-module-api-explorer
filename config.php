<?php

/**
 * Configuration for OpenEMR API Explorer.
 * Keeps environment-specific settings centralized.
 * Designed to minimize CLI/editor use by app users.
 */

// Bookmarks

// https://localhost/openemr/modules/oe-module-api-explorer/client_register.php
// https://localhost/openemr/modules/oe-module-api-explorer/oeApiExplorer.php?cleanSession

// Determine domain and root directory automatically except if CLI then add host and root
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$selectedSite = $selectedSite ?? $_SESSION['selectedSite'] ?? 'localhost';
$api_site = $selectedSite;
// List of supported OpenEMR sites for API testing
$apiSites = [
    'localhost'                         => 'https://localhost/openemr', // Replace with your local OpenEMR URL
    'remote-ubuntu-php-8.3'   => 'https://six.openemr.io/a/openemr', // Remotes need work. Could be proxie issues. May need to set override in Config connector for proxy!
    'remote-alpine-php-8.2'     => 'https://two.openemr.io/a/openemr',
    'remote-alpine-php-8.3'     => 'https://one.openemr.io/a/openemr',
    'remote-alpine-php-8.4'     => 'https://seven.openemr.io/a/openemr',
    'remote-docker'                 => 'https://localhost:9300'
];

// Set default site if not passed via query or session
if (php_sapi_name() !== 'cli') {
    session_start();
    $selectedSite = $_SESSION['selectedSite'] ?? $_GET['api_site'] ?? 'localhost';
    $api_site = $selectedSite;
    $_SESSION['selectedSite'] = $selectedSite;
    $base_path = $apiSites[$selectedSite] ?? reset($apiSites);
} else {
    $base_path =  "{$scheme}://localhost/openemr"; // or as argument
    foreach ($argv as $arg) {
        if (stripos($arg, '--base_path=') === 0) {
            $base_path = substr($arg, strlen('--basePath='));
        }
    }
}

$web_root = $web_root ?? '';
$domain = "{$scheme}://{$host}{$web_root}";
$appbase_path = "$domain/modules/oe-module-api-explorer";
$app_path = "{$domain}/modules/oe-module-api-explorer";

// Toggle whether to expose JWKS via static file (requires valid SSL for JWKS fetch)
// or dynamically via the API Explorer endpoint.
// If false, the JWKS will be fetched dynamically from the API Explorer endpoint.
// If true, the JWKS will be served from a static file in the client_jwks.json file.
// IMPORTANT: If you use the static file, your SSL certificate must include the same domain as used in base path.
// and the file must be accessible via the web server.
$use_keys_file = false;
$jwks_app_path = $use_keys_file ? "$base_path/modules/oe-module-api-explorer/clients_keys/{$selectedSite}_jwks.json" : null;

// Dynamic API endpoint configuration
$GLOBALS['ApiConfig'] = [
    'JWKS_LOCATION_URL'        => $jwks_app_path,
    'AUTHORIZATION_ENDPOINT'   => "{$base_path}/oauth2/default/authorize",
    'TOKEN_ENDPOINT'           => "{$base_path}/oauth2/default/token",
    'LOGOUT_REDIRECT_URI'      => "{$base_path}/oauth2/default/logout.php",
    'REGISTER_CLIENT_ENDPOINT' => "{$base_path}/oauth2/default/registration",
    'FHIR_SERVER_URL'          => "{$base_path}/apis/default/fhir",
    'API_SERVER_URL'           => "{$base_path}/apis/default/api",
    'REDIRECT_URI'             => "{$app_path}/oeApiExplorer.php"
];

// If using static JWKS file, ensure it exists
const PRIVATE_SCOPES = 'openid offline_access api:oemr api:fhir api:port user/allergy.read user/allergy.write user/appointment.read user/appointment.write user/dental_issue.read user/dental_issue.write user/document.read user/document.write user/drug.read user/encounter.read user/encounter.write user/facility.read user/facility.write user/immunization.read user/insurance.read user/insurance.write user/insurance_company.read user/insurance_company.write user/insurance_type.read user/list.read user/medical_problem.read user/medical_problem.write user/medication.read user/medication.write user/message.write user/patient.read user/patient.write user/practitioner.read user/practitioner.write user/prescription.read user/procedure.read user/soap_note.read user/soap_note.write user/surgery.read user/surgery.write user/transaction.read user/transaction.write user/vital.read user/vital.write user/AllergyIntolerance.read user/CareTeam.read user/Condition.read user/Coverage.read user/Encounter.read user/Immunization.read user/Location.read user/Medication.read user/MedicationRequest.read user/Observation.read user/Organization.read user/Organization.write user/Patient.read user/Patient.write user/Practitioner.read user/Practitioner.write user/PractitionerRole.read user/Procedure.read patient/encounter.read patient/patient.read patient/AllergyIntolerance.read patient/CareTeam.read patient/Condition.read patient/Coverage.read patient/Encounter.read patient/Immunization.read patient/MedicationRequest.read patient/Observation.read patient/Patient.read patient/Procedure.read';

const SYSTEM_SCOPES = 'openid offline_access api:oemr api:fhir api:port system/Patient.read system/AllergyIntolerance.read system/CarePlan.read system/CareTeam.read system/Condition.read system/Coverage.read system/Device.read system/DiagnosticReport.read system/DocumentReference.read system/Encounter.read system/Goal.read system/Group.read system/Immunization.read system/Location.read system/Medication.read system/MedicationRequest.read system/Observation.read system/Organization.read system/Person.read system/Practitioner.read system/PractitionerRole.read system/Procedure.read system/Provenance.read system/patient.read system/*.$export system/*.$export system/Patient.$export';

const LIMITED_SCOPES = 'openid offline_access api:oemr api:fhir api:port user/CareTeam.read user/Condition.read user/Encounter.read user/Immunization.read user/Location.read user/Medication.read user/MedicationRequest.read user/Observation.read user/Organization.read user/Organization.write user/Patient.read user/Patient.write user/Practitioner.read user/Practitioner.write user/Procedure.read patient/encounter.read patient/patient.read patient/Patient.read patient/Encounter.read';

const PUBLIC_SCOPES = 'openid offline_access api:oemr api:fhir api:port patient/encounter.read patient/patient.read patient/Patient.read patient/Encounter.read patient/AllergyIntolerance.read patient/CareTeam.read patient/MedicationRequest.read';
