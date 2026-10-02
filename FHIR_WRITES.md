# FHIR writes

Two panels test the write half of the FHIR API. Both use the token already in the Explorer
session, and the token stays on the server: the browser only sends bodies.

Get a write-capable token first: **Confidential** client, **Authorization Code**, **Fetch** once.

## FHIR writes panel

1. **Load** reads the write catalog and resolves the references the bodies need from your server:
   a patient, practitioner, facility, insurer, encounter and questionnaire.
2. Pick a **Resource**. The line under it shows the scope required and whether your token has it,
   the ACL the route enforces, the verbs available, and any reference that couldn't be resolved.
3. The **Request body** is filled from the template with real ids substituted. Edit it freely;
   **reset to template** restores it.
4. Send it one of three ways:
   - **Send**: one POST or PUT, response shown as-is.
   - **POST → PUT → verify**: create, update, read back.
   - **Run all resources**: the round trip for every resource, with a pass / fail / skipped list.

On an empty install, **Create missing fixtures** POSTs the prerequisites through the same API.

### What the round trip catches

- A create that answers 201 without an id. The id key differs by resource: most answer `uuid`,
  Encounter `euuid`, Appointment `pc_uuid`.
- A PUT that answers 200 with an empty body.
- A created resource that can't be read back.
- A body that references something unresolved is **skipped** with the reason, not sent broken.

### The catalog

`fhir_write_templates.php` has one entry per write route. Bodies mirror OpenEMR's own PHPUnit
fixtures (`tests/Tests/Fixtures/FHIR/*.json`), so a failure points at the server, not at a
hand-typed payload.

**Writable (22):** AllergyIntolerance, Appointment (create only), CarePlan, CareTeam, Condition,
Coverage, Device, Encounter, Goal, Immunization, Medication, MedicationRequest, Observation,
Organization, Patient, Person, Practitioner, PractitionerRole, Questionnaire,
QuestionnaireResponse, RelatedPerson, ServiceRequest.

**Routes that answer 405 (7):** DiagnosticReport, DocumentReference, Group, Location,
MedicationDispense, Procedure, Provenance. These have POST/PUT routes that deliberately answer
"not supported". The suite passes them on a 405 and reports them as skipped when the scope check
answers 401/403 first.

Observation needs OpenEMR PR #14217. On a server without it, Register Clients leaves the scope
out and the panel shows Observation as "scope missing".

### Observation variants

Observation writes cover vital signs. The server requires a `vital-signs` category, an encounter
that belongs to the patient, and an `effectiveDateTime`. Vitals posted for one encounter at one
time share a row, so a POST for a reading that row already holds is refused, and a PUT can't
change the date. Each body therefore gets a fresh `{{effective}}` timestamp.

With Observation selected, a dropdown next to *reset to template* offers more bodies:

| Variant | Expect |
|---|---|
| Body weight in kg, temperature in Cel | 201, converted to storage units |
| Blood pressure panel (components), pulse oximetry | 201 |
| LOINC coding listed second | 201 |
| No encounter, no category | 400 |
| Smoking status (social-history), BMI | 400, read-only |
| Zero value, unit that can't be converted | 400 |

Running the round trip with the default body also runs every variant and two follow-up checks:
a repeat POST of the same reading and a PUT that moves the date must both be refused.

### Placeholders

`{{patient}}` `{{practitioner}}` `{{encounter}}` `{{facility}}` `{{orgProvider}}`
`{{orgInsurer}}` `{{questionnaire}}` come from the resolved context. `{{unique}}` and
`{{uniqueDigits}}` keep repeat runs from colliding on natural keys such as an NPI.
`{{effective}}` is a fresh past timestamp per body.

### Adding a resource

Add an entry to `fhir_write_templates.php`:

```php
'Example' => [
    'scope' => 'user/Example.write',
    'acl' => 'patients/med',          // shown when a 401 needs explaining
    'verbs' => ['POST', 'PUT'],
    'readById' => true,
    'needs' => ['patient'],           // context keys the body references
    // 'idKey' => 'uuid',             // if the create response names the id differently
    'body' => ['resourceType' => 'Example', 'subject' => ['reference' => 'Patient/{{patient}}']],
    // 'variants' => ['name' => ['label' => '...', 'body' => [...], 'expect' => 400, 'contains' => 'phrase']],
],
```

Add `user/Example.read` and `user/Example.write` to `FHIR_READ_SCOPES` and `FHIR_WRITE_SCOPES` in
`config.php`, run Register Clients, and get a new token.

## FHIR write stress panel

Sends many writes at once to find what single requests can't: id allocator collisions,
transaction boundaries, duplicate uuids.

1. **Load resources**, tick the ones to hit.
2. Set **Iterations** (per resource, up to 200) and **Concurrency** (in flight, up to 32).
3. Choose **POST only** or **POST then PUT**.
4. **Dry run** shows what would be sent. **Run load** sends it.

The report gives per-resource success counts, status spread, p50 / p95 / max latency, whether
every create returned a distinct id, and a sample of failures.

**It writes real rows and can't delete them.** The FHIR API has no DELETE. Each row carries a run
tag in its free-text field so you can find it later; the panel prints the tag and sample SQL.
Observation vitals have no free-text field: look for the Vitals forms on the encounter used.

The fan-out runs server-side with `curl_multi`. Driving it from the browser wouldn't work: PHP's
session lock would run the requests one after another.
