# Contributing

Thanks for helping. This tool exists so OpenEMR developers can see the API working and catch it
when it doesn't.

## Ground rules

- Never commit `config.local.php` or anything generated in `clients_keys/`. Both are git-ignored;
  check `git status` before you push.
- Keep it dependency-free: plain PHP, the libraries OpenEMR ships, Bootstrap 4 and vanilla
  JavaScript. No Composer or npm step.
- Tokens and secrets stay server-side. A panel's JavaScript talks to its own PHP endpoint, and
  the endpoint attaches the bearer token.
- Probes and checks must not leave data behind unless the panel says clearly that it writes.
- Style the page with the existing `--xp-*` variables so it works in OpenEMR's light and dark themes.

## Common changes

**Add a writable FHIR resource.** One entry in `fhir_write_templates.php`, plus the read and
write scopes in `config.php`. See [FHIR_WRITES.md](FHIR_WRITES.md#adding-a-resource).

**Add a scope profile.** One entry in `scope_lab_profiles.php`. Use `{ctx}` for the context;
expected outcomes are computed from the scopes. See [SCOPE_LAB.md](SCOPE_LAB.md).

**Add a panel.** Follow the existing three-file pattern:

| File | Role |
|---|---|
| `<name>_card.php` | Markup inside `explorer_panel_open()` / `explorer_panel_close()`, plus its script |
| `<name>.php` | JSON endpoint: `session_start()`, `require_once 'config.php'`, read the token from the session |
| `<name>_templates.php` | Optional data file |

Include the card from `oeApiExplorer.php`. Give the panel a stable id: its open/closed state is
remembered under that id.

## Before opening a pull request

```bash
for f in *.php src/*.php; do php -l "$f"; done
```

Then run the panels you touched against a development OpenEMR and say in the PR which OpenEMR
version or commit you tested with. Add a line to `CHANGELOG.md`.

## Reporting bugs

Include the OpenEMR version or commit, the PHP version, the panel, and the output. For the Scope
Lab, attach **Export JSON**; it contains scope strings and verdicts, no tokens.
