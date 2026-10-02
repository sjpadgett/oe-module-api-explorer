<?php

/**
 * Your settings for the OpenEMR API Explorer.
 *
 *   1. Copy this file to config.local.php (same folder).
 *   2. Edit the copy. config.local.php is git-ignored.
 *
 * Every key is optional -- delete what you do not need. With no config.local.php at all the
 * Explorer uses a single site, https://localhost/openemr.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

return [
    // The OpenEMR servers to test against: name => base URL, no trailing slash.
    //
    // The base URL is what comes before /apis/default/fhir and /oauth2/default/authorize.
    // The name is yours to choose; it becomes part of the registered client names
    // ("<name> Confidential Auth-Code Client") and of the credential files in clients_keys/.
    //
    // A name starting with "remote-" marks a server whose database this install cannot reach:
    // Register Clients then leaves existing clients alone and does not enable the new ones --
    // enable them on that server under Admin > System > API Clients.
    'sites' => [
        'localhost' => 'https://localhost/openemr',
        // 'docker'        => 'https://localhost:9300',
        // 'remote-demo'   => 'https://demo.example.org/openemr',
    ],

    // Which site is selected the first time the page loads. Defaults to the first one above.
    // 'default_site' => 'localhost',

    // OpenEMR multisite id used in the URLs (/apis/<id>/fhir, /oauth2/<id>/token).
    // 'openemr_site' => 'default',

    // false (default): the public key set is sent inline when registering -- works everywhere.
    // true: registration sends a jwks_uri pointing at clients_keys/<site>_jwks.json instead.
    //       The OpenEMR server must be able to fetch that URL over HTTPS with a valid certificate.
    // 'use_keys_file' => false,

    // Request user/Observation.write (OpenEMR PR #14217). Safe to leave on: Register Clients
    // skips any scope the server does not publish.
    // 'observation_write' => true,

    // By default the Explorer only answers requests from this machine and private networks,
    // because it bypasses the OpenEMR login and stores client secrets. Set true on a shared
    // development server. Never on anything holding real patient data.
    // 'allow_remote' => false,

    // The public URL of this folder, if the Explorer guesses it wrong (reverse proxy or port
    // mapping). It is used for the OAuth redirect and SMART launch URLs.
    // 'app_url' => 'https://dev.example.org/devtools/oe-module-api-explorer',
];
