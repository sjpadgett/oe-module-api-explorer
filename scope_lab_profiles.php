<?php

/**
 * scope_lab_profiles.php — the scope sets the Scope Lab registers, requests and probes.
 *
 * Plain data; edit freely. Each profile is instantiated per grant/context:
 *   {ctx}  becomes system (client_credentials), user, or patient
 *   base   scopes for the chosen context are prepended automatically (see 'base' below)
 *
 * Profile keys
 *   label     short name shown in the table
 *   group     v1 | v2 | mixed | negative | edge  (drives the version badge and filters)
 *   register  scopes the lab client is registered with
 *   request   scopes asked for at the token/authorize step (defaults to register)
 *   contexts  which of system/user/patient the profile makes sense in (default all three)
 *   expect    optional overrides; otherwise derived:
 *               registration  accept | reject      (derived: reject if any scope is not
 *                                                   published or not well-formed)
 *               token         accept | reject | reject_or_drop
 *   strip     scopes removed after the base is prepended (e.g. drop api:fhir for a gate test)
 *   note      what the profile is for -- shown in the UI and the exported report
 *
 * Expected probe outcomes are never written here; they are computed from the scopes by
 * ScopeAlgebra::predict() so the profiles cannot drift out of step with the assertions.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$labCat = 'category=http://terminology.hl7.org/CodeSystem/observation-category|laboratory';
$condCat = 'category=http://terminology.hl7.org/CodeSystem/condition-category|problem-list-item';

return [
    // Prepended to every profile for the context. Kept separate so a profile's own
    // scope list is only the thing under test.
    'base' => [
        'system' => 'api:fhir',
        'user' => 'openid offline_access fhirUser api:fhir api:oemr',
        'patient' => 'openid offline_access fhirUser launch/patient api:fhir',
    ],

    // FHIR resources used as negative controls: probed on every run and expected to be
    // denied unless the profile names them. Catches a server that over-grants.
    'controls' => [
        'fhir' => ['Immunization', 'Organization'],
        'standard' => ['facility'],
    ],

    'profiles' => [
        // ---------------------------------------------------------------- v1 only
        'v1-read' => [
            'label' => 'v1 read',
            'group' => 'v1',
            'register' => '{ctx}/Patient.read {ctx}/Observation.read {ctx}/Condition.read {ctx}/Encounter.read',
            'note' => 'Baseline SMART v1. read must grant both search and read-by-id.',
        ],
        'v1-readwrite' => [
            'label' => 'v1 read + write',
            'group' => 'v1',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.read {ctx}/Patient.write {ctx}/QuestionnaireResponse.read {ctx}/QuestionnaireResponse.write',
            'note' => 'The pair the Questionnaire app relies on today. write must grant create and update.',
        ],
        'v1-wildcard' => [
            'label' => 'v1 .*',
            'group' => 'v1',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.*',
            'note' => 'v1 wildcard = cruds. Usually unpublished; if the server publishes it, all verbs must pass.',
        ],

        // ---------------------------------------------------------------- v2 only
        'v2-rs' => [
            'label' => 'v2 .rs',
            'group' => 'v2',
            'register' => '{ctx}/Patient.rs {ctx}/Observation.rs {ctx}/Condition.rs {ctx}/Encounter.rs',
            'note' => 'v2 equivalent of v1-read. Results must match v1-read exactly.',
        ],
        'v2-r-only' => [
            'label' => 'v2 .r (no search)',
            'group' => 'v2',
            'register' => '{ctx}/Patient.r {ctx}/Observation.r',
            'note' => 'Read-by-id without search. Search must be denied -- the r/s split is where v2 enforcement usually leaks.',
        ],
        'v2-s-only' => [
            'label' => 'v2 .s (no read)',
            'group' => 'v2',
            'register' => '{ctx}/Patient.s {ctx}/Observation.s',
            'note' => 'Search without read-by-id. GET Resource/{id} must be denied.',
        ],
        'v2-cruds' => [
            'label' => 'v2 .cruds',
            'group' => 'v2',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.cruds {ctx}/QuestionnaireResponse.cruds',
            'note' => 'Full v2 write. Rejected at registration until the server publishes v2 write scopes.',
        ],
        'v2-cu-only' => [
            'label' => 'v2 .cu (write, no read)',
            'group' => 'v2',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.cu {ctx}/QuestionnaireResponse.cu',
            'note' => 'Create/update with no read. Reads must be denied even though writes pass.',
        ],
        'v2-granular' => [
            'label' => 'v2 granular ?category',
            'group' => 'v2',
            'contexts' => ['user', 'patient'],
            'register' => "{ctx}/Observation.rs?{$labCat} {ctx}/Condition.rs?{$condCat}",
            'note' => 'ONC g(10) granular scopes. Matching category allowed; other category and no category must be filtered (nothing outside the granted category returned) or denied.',
        ],

        // ---------------------------------------------------------------- mixed
        'mixed-split' => [
            'label' => 'v1 + v2, different resources',
            'group' => 'mixed',
            'register' => '{ctx}/Patient.read {ctx}/Observation.rs {ctx}/Condition.read {ctx}/Encounter.rs',
            'note' => 'Each resource on one version. Should behave exactly like v1-read / v2-rs.',
        ],
        'mixed-overlap-read' => [
            'label' => 'v1 + v2, same resource (read)',
            'group' => 'mixed',
            'register' => '{ctx}/Patient.read {ctx}/Patient.rs {ctx}/Observation.read {ctx}/Observation.rs',
            'note' => 'Redundant spellings of the same permission. Nothing may be lost or gained.',
        ],
        'mixed-rw-overlap' => [
            'label' => 'v2 read + v1 write, same resource',
            'group' => 'mixed',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.rs {ctx}/Patient.write {ctx}/QuestionnaireResponse.rs {ctx}/QuestionnaireResponse.write',
            'note' => 'The consent-screen bug: the form rebuilt one version per resource and dropped the write. Expect r s c u d.',
        ],
        'mixed-r-plus-s' => [
            'label' => 'v2 .r + v2 .s as separate scopes',
            'group' => 'mixed',
            'register' => '{ctx}/Patient.r {ctx}/Patient.s',
            'note' => 'Two v2 scopes that union to .rs. A per-resource "last one wins" rebuild loses one of them.',
        ],

        // ---------------------------------------------------------------- negative
        'neg-out-of-order' => [
            'label' => 'v2 out of order (.sr)',
            'group' => 'negative',
            'register' => '{ctx}/Patient.sr',
            'note' => 'Malformed per SMART v2 (must be c r u d s order). Must be rejected, never normalised silently.',
        ],
        'neg-no-api-gate' => [
            'label' => 'no api:fhir gate',
            'group' => 'negative',
            'register' => '{ctx}/Patient.rs {ctx}/Observation.rs',
            'strip' => ['api:fhir'],
            'note' => 'Resource scopes without api:fhir. Every FHIR probe must be denied.',
        ],
        'neg-over-request' => [
            'label' => 'request beyond registration',
            'group' => 'negative',
            'register' => '{ctx}/Patient.rs',
            'request' => '{ctx}/Patient.rs {ctx}/Observation.rs',
            'expect' => ['token' => 'reject_or_drop'],
            'note' => 'Asks for a scope the client was not registered for. Must fail invalid_scope or drop Observation -- never grant it.',
        ],

        // ---------------------------------------------------------------- edge
        'edge-downscope' => [
            'label' => 'request subset of registration',
            'group' => 'edge',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.rs {ctx}/Patient.write {ctx}/Observation.rs',
            'request' => '{ctx}/Patient.rs',
            'note' => 'Registered wide, requested narrow. Granted must equal requested, not registered.',
        ],
        'edge-standard-v1' => [
            'label' => 'standard API v1',
            'group' => 'edge',
            'contexts' => ['user'],
            'register' => '{ctx}/patient.read {ctx}/patient.write {ctx}/practitioner.read {ctx}/facility.read',
            'note' => 'api:oemr lowercase scopes. Kept apart from FHIR: same name, different scope space.',
        ],
        'edge-standard-v2' => [
            'label' => 'standard API v2',
            'group' => 'edge',
            'contexts' => ['user'],
            'register' => '{ctx}/patient.rs {ctx}/practitioner.rs',
            'note' => 'v2 letters on api:oemr names. Rejected unless the server publishes them.',
        ],
        'edge-case-collision' => [
            'label' => 'FHIR + standard same name',
            'group' => 'edge',
            'contexts' => ['user'],
            'register' => '{ctx}/Patient.rs {ctx}/patient.read',
            'note' => 'user/Patient and user/patient are different scopes. Neither may leak into the other API.',
        ],
    ],
];
