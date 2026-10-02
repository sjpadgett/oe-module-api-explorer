# Scope Lab

Answers one question: **are this server's scopes reliable?** For SMART v1, v2 and mixed scope
sets, across every grant type, it checks that a client gets the permissions it asked for and
that the API enforces exactly those.

## Run it

1. Click **Discover scopes**. This reads the server's published scope list, which the lab uses
   to predict whether each registration should be accepted.
2. Choose a **Grant** and **Context**:

   | Grant | Context | Runs |
   |---|---|---|
   | Client Credentials (JWT) | `system` | unattended |
   | Password | `user` or `patient` | unattended; needs the password grant enabled in OpenEMR |
   | Auth Code, confidential | `user` or `patient` | through login and consent, one profile at a time |
   | Auth Code, public (PKCE) | `user` or `patient` | through login and consent, one profile at a time |

3. Tick profiles (the *Select* links pick a group) and click **Run selected**.
4. For Auth Code runs, **leave every scope ticked on the consent screen and approve**. You land
   back on the panel and the run is analysed. Anything missing from the grant is then the
   server's doing, which is what the lab is looking for.

The profile list scrolls. Greyed rows don't apply to the chosen context: write profiles need
`user`.

## What a run does

1. **Register.** A new client named `<site> ScopeLab <profile> <grant>` with exactly the
   profile's scopes. The previous one is deleted first.
2. **Token.** Requests the profile's scopes.
3. **Compare.** Expected grant = requested ∩ registered. Granted, the token's JWT `scopes` claim
   and the introspection result are each reduced to create / read / update / delete / search per
   resource and compared.
4. **Probe.** One API call per resource and permission, plus negative controls.
5. **Refresh** (when there's a refresh token): same scope, a superset, a subset.

## Reading the result row

| Column | Pass means |
|---|---|
| **Reg** | Registration was accepted or refused as predicted from the published scopes |
| **Token** | A token was issued or refused as expected |
| **Grant** | Granted permissions equal requested ∩ registered |
| **JWT** | The token's own `scopes` claim agrees with the token response |
| **Intro** | The introspection endpoint agrees with the token response |
| **Probes** | The API allowed and refused what the granted scopes say it should |
| **Refresh** | Same scope stayed equivalent, superset was refused, subset narrowed |

Click a row for the scope lists, the differences, a per-resource permission grid, every probe
and the refresh results.

### Compared by permission, not by text

| Requested | Granted | Verdict |
|---|---|---|
| `user/Patient.read` | `user/Patient.rs` | pass, *respelled*: same permissions |
| `user/Patient.rs user/Patient.write` | `user/Patient.rs` | **fail, lost c u d** |
| `user/Patient.rs` | `user/Patient.rs user/Observation.rs` | **fail, gained** |

OpenEMR leaves `api:fhir`, `api:oemr` and `api:port` out of the token response's scope string on
purpose, while the token still carries them. The lab adds them back from the JWT claim and says
so next to **Granted**.

### Probes

| Permission | Request |
|---|---|
| search | `GET Resource?_count=1` |
| read | `GET Resource/<id>`, a real id from the search or a fixed fake one |
| create | `POST Resource` with body `{}` |
| update | `PUT Resource/<fake id>` with body `{}` |

Write probes are non-destructive: the server checks the scope first, then refuses the empty body.

| Response | Meaning |
|---|---|
| 401 / 403 | the scope was refused |
| 2xx | allowed |
| 400 / 404 / 405 / 409 / 422 | the scope was accepted and the request failed for another reason |
| 5xx or no response | inconclusive |

Each probe is scored twice. **vs granted**: does the server enforce what it said it granted?
**vs asked**: did the caller get what it requested? A write dropped at consent passes the first
and fails the second.

`Immunization`, `Organization` and `facility` are probed on every run as negative controls. They
must be refused unless the profile names them.

### Granular scopes

For a scope such as `user/Observation.rs?category=…|laboratory` the lab searches with the
matching category, with another category, and with none. A 2xx alone can't tell a correctly
filtered result from a leak, so the returned entries are checked: every one must satisfy the
granted constraint. Anything outside it is reported as **LEAK**.

## Profiles

Defined in `scope_lab_profiles.php`. `{ctx}` becomes `system`, `user` or `patient`, and the base
scopes for that context (`openid`, `api:fhir` and so on) are added automatically.

| Group | Name in the table | Id | Tests |
|---|---|---|---|
| v1 | v1 read | `v1-read` | `.read` gives search and read |
| v1 | v1 read + write | `v1-readwrite` | `.write` gives create and update |
| v1 | v1 .* | `v1-wildcard` | wildcard, usually unpublished |
| v2 | v2 .rs | `v2-rs` | must match `v1-read` |
| v2 | v2 .r (no search) | `v2-r-only` | search must be refused |
| v2 | v2 .s (no read) | `v2-s-only` | read by id must be refused |
| v2 | v2 .cruds | `v2-cruds` | full v2 write |
| v2 | v2 .cu (write, no read) | `v2-cu-only` | reads must be refused |
| v2 | v2 granular ?category | `v2-granular` | filtering under a category constraint |
| mixed | v1 + v2, different resources | `mixed-split` | each resource on one version |
| mixed | v1 + v2, same resource (read) | `mixed-overlap-read` | redundant spellings |
| mixed | v2 read + v1 write, same resource | `mixed-rw-overlap` | the write must survive consent |
| mixed | v2 .r + v2 .s as separate scopes | `mixed-r-plus-s` | two scopes that add up to `.rs` |
| negative | v2 out of order (.sr) | `neg-out-of-order` | malformed scope must be rejected |
| negative | no api:fhir gate | `neg-no-api-gate` | every FHIR call must be refused |
| negative | request beyond registration | `neg-over-request` | must fail or drop, never grant |
| edge | request subset of registration | `edge-downscope` | granted = requested, not registered |
| edge | standard API v1 / v2 | `edge-standard-v1`, `edge-standard-v2` | `api:oemr` scopes |
| edge | FHIR + standard same name | `edge-case-collision` | `Patient` vs `patient` |

The **Custom** boxes take any register / request pair for a one-off: type the scopes with
`{ctx}` and click **Run custom**.

### Adding a profile

```php
'my-profile' => [
    'label' => 'short name',
    'group' => 'mixed',                 // v1 | v2 | mixed | negative | edge
    'contexts' => ['user'],             // optional; default all three
    'register' => '{ctx}/Patient.rs {ctx}/Patient.write',
    'request' => '{ctx}/Patient.rs',    // optional; default = register
    'expect' => ['token' => 'reject'],  // optional override
    'note' => 'what this is for',
],
```

Expected probe outcomes are never written by hand. They're computed from the scopes.

## Other buttons

- **Use token in Explorer** (in a run's detail): puts that run's token in the Explorer session,
  so the write and stress panels use it.
- **Copy Markdown**: a results table and findings list for an issue or pull request.
- **Export JSON**: every run for the site. No tokens.
- **Clear runs** / **Clear + delete lab clients**: the second also removes this site's ScopeLab
  clients from `oauth_clients` and their credential files.

## Limits

- Probes assume the scope check runs before body validation and record lookup, which is how
  OpenEMR's route handler works. A route that validated first would make a refusal look like a 400.
- The registration prediction is only as good as the server's published scope list. Use `expect`
  in a profile to override it.
- Password-grant clients are registered with `grant_types: ["password"]`. If a server refuses
  that, the run reports it.
- Clients are enabled in the database of the OpenEMR the Explorer runs in. For a site on another
  install, enable them there.

## Files

| File | Role |
|---|---|
| `scope_lab_card.php` | The panel |
| `scope_lab.php` | JSON endpoint: `state`, `discover`, `run`, `analyze`, `adopt`, `clear`, `export` |
| `scope_lab_lib.php` | Registration, tokens, introspection, probes, refresh tests, verdicts |
| `scope_lab_callback.php` | OAuth redirect for lab clients |
| `scope_lab_profiles.php` | The profiles |
| `src/ScopeAlgebra.php` | Scope parsing, comparison, prediction, constraint matching |
