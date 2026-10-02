# Security

The API Explorer is a development and testing tool. It is deliberately permissive so that it
works on a fresh development install with a self-signed certificate. Don't install it on a
server that holds real patient data.

## What it does that a production app must not

- **Skips the OpenEMR login.** Its pages set `$ignoreAuth`, so anyone who can reach the URL can
  use it.
- **Stores secrets on disk.** `clients_keys/` holds the private signing key and each client's
  secret, readable by the web server.
- **Turns off TLS verification** on its calls to OpenEMR, so self-signed certificates work.
- **Enables its own clients** directly in the `oauth_clients` table, skipping the administrator
  approval a real client goes through.
- **Writes to the chart.** The write and stress panels create real rows that the API can't delete.

## What limits the exposure

- By default it answers only requests from the local machine and private network ranges.
  `allow_remote` in `config.local.php` lifts that.
- `clients_keys/.htaccess` stops Apache serving anything in that folder except the public key
  sets. It has no effect on nginx: add an equivalent rule there.
- `.gitignore` keeps `config.local.php` and everything generated in `clients_keys/` out of commits.
- Access tokens and client secrets stay in the PHP session and on disk. They are never sent to
  the browser.
- The Scope Lab's write probes send an empty body, which the server refuses after the scope check,
  so they don't create data.

## Before sharing a copy

Check that it contains no `config.local.php` and nothing in `clients_keys/` beyond `.gitkeep`,
`.htaccess` and `README.md`.

## Reporting a problem

For an issue in this tool, open an issue at
<https://github.com/sjpadgett/oe-module-api-explorer>. If the Explorer shows you a security
problem in OpenEMR itself, such as a scope that isn't enforced, report it privately through
OpenEMR's security policy (<https://github.com/openemr/openemr/security/policy>) and not in a
public issue.
