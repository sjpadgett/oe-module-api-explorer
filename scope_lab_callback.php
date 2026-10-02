<?php

/**
 * scope_lab_callback.php — OAuth redirect target for Scope Lab auth-code clients.
 *
 * Kept apart from oeApiExplorer.php's own callback so a lab run never touches the
 * Explorer's session token. Exchanges the code, records the token block on the run, and
 * sends the browser back to the panel, which analyses the run on arrival.
 *
 * An authorize-endpoint refusal (error=invalid_scope, access_denied...) is recorded as the
 * token result rather than shown as a failure page: for a negative profile it is the answer.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

session_start();
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once '../../interface/globals.php';
require_once 'config.php';
require_once __DIR__ . '/scope_lab_lib.php';

$state = $_GET['state'] ?? '';
$pending = is_string($state) ? ($_SESSION['scope_lab']['pending'][$state] ?? null) : null;
if (!is_array($pending)) {
    http_response_code(400);
    die(text('Scope Lab: unknown or already-used state. Start the run again from the panel.'));
}
unset($_SESSION['scope_lab']['pending'][$state]);

$runId = (string) $pending['run'];
$run = $_SESSION['scope_lab']['runs'][$runId] ?? null;
if (!is_array($run)) {
    http_response_code(400);
    die(text('Scope Lab: the run for this callback is gone (session cleared?).'));
}

$access = null;
$refresh = null;
if (!empty($_GET['error'])) {
    $run['token'] = [
        'ok' => false,
        'status' => 0,
        'ms' => 0,
        'error' => 'authorize: ' . (string) $_GET['error'] . (!empty($_GET['error_description']) ? ' — ' . (string) $_GET['error_description'] : ''),
        'granted' => [],
        'grantedSource' => 'none',
        'claimScopes' => null,
        'hasRefresh' => false,
        'patient' => null,
        'expiresIn' => null,
    ];
} else {
    try {
        $client = labLoadClient((string) $run['profile'], (string) $run['grant']);
        $fields = labClientAuth($client, (string) $run['grant']) + [
            'grant_type' => 'authorization_code',
            'code' => (string) ($_GET['code'] ?? ''),
            'redirect_uri' => labCallbackUri(),
            'code_verifier' => (string) $pending['verifier'],
        ];
        $parsed = labTokenResult(labPostForm((string) $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'], $fields), (array) $run['requested']);
        $run['token'] = $parsed['block'];
        $access = $parsed['access'];
        $refresh = $parsed['refresh'];
    } catch (Throwable $e) {
        $run['token'] = [
            'ok' => false, 'status' => 0, 'ms' => 0, 'error' => $e->getMessage(), 'granted' => [],
            'grantedSource' => 'none', 'claimScopes' => null, 'hasRefresh' => false, 'patient' => null, 'expiresIn' => null,
        ];
    }
}

// Analysis happens from the panel (it can take a few seconds of probing); the verdicts
// here cover registration and token so a refused authorize still reads correctly.
$run['pendingOptions'] = $pending['options'] ?? [];
$run['verdicts'] = labVerdicts($run);
labStoreRun($run, $access, $refresh);

header('Location: oeApiExplorer.php?' . http_build_query(['scope_lab_run' => $runId]) . '#scopeLabPanel');
exit;
