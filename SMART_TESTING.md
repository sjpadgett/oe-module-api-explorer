# SMART EHR launch test extension

The API Explorer registers a fourth client named `SMART EHR Launch` alongside the JWT,
Confidential and Public clients. Unlike the other three it is launched *from* OpenEMR, not from
the Explorer page.

1. Install the Explorer under `devtools/oe-module-api-explorer/` (see [INSTALLATION.md](INSTALLATION.md)).
2. Open the Explorer, pick your **API Site**, and click **Register Clients** — or go straight to
   `devtools/oe-module-api-explorer/client_register.php?regen=1&api_site=<site>`.
   This registers all four clients; the SMART one carries `initiate_login_uri` and `launch_uris`
   pointing at `smart_launch.php`.
3. Confirm the generated SMART client is enabled and has its launch URI
   (**Admin → System → API Clients**).
4. Launch it from OpenEMR's SMART Enabled Apps card.
5. The Explorer displays discovery metadata and token-response launch context.

Its scopes come from `SMART_SCOPES` in `config.php`, which is separate from the scope sets the
other clients use. Editing it requires re-running Register Clients.

The client discovers authorization and token endpoints from the incoming `iss`, includes the opaque `launch` parameter and `aud`, uses PKCE, validates OAuth state, and uses the token response as the source of patient/encounter context.

## Questionnaire assessment launch context

When launched from **Patient → Assessments → FHIR Assessments**, the SMART token response may include:

```json
{
  "intent": "questionnaire.assessment.dialog",
  "fhirContext": [
    {"reference": "Questionnaire/<FHIR id>"},
    {"reference": "QuestionnaireResponse/<FHIR id>"}
  ],
  "appContext": "{\"workflow\":\"questionnaire-assessment\",\"action\":\"review\",\"returnContext\":\"patient-fhir-assessments\"}"
}
```

The API Explorer keeps the existing SMART discovery and token diagnostics, decodes `appContext`, and automatically retrieves the Patient, optional Encounter, and each supported relative reference from `fhirContext`. Use **Open in Explorer** to place a launch resource into the normal resource/query controls. The workflow action is `start`, `continue`, or `review` based on the server-validated assessment context.

## Where the launch context shows up

After a launch the Explorer forces client `smart`, API `fhir` and the authorization-code grant,
and renders the SDOH workspace instead of the plain fetch form. The normal resource/query controls
stay available — **Open in Explorer** moves a launch resource into them.

The FHIR writes card is independent of the launch: it uses whatever token is in session. A SMART
token carries `SMART_SCOPES`, which covers reading and writing `QuestionnaireResponse` but not the
wider `FHIR_WRITE_SCOPES` set, so for the full write matrix use the Confidential client instead.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "SMART issuer is not configured in API Explorer" | The `iss` OpenEMR sent doesn't match any site's base URL. Add that URL to `sites` in `config.local.php`. |
| "Missing SMART client registration" | Run Register Clients for that site. |
| The app isn't listed in OpenEMR | Enable the `<site> SMART EHR Launch Client` under Admin → System → API Clients. |
| Launch works but writes fail | The SMART client only writes `QuestionnaireResponse`. |
