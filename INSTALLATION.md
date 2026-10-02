# Installation

## 1. Requirements

- OpenEMR 8.0 or current master (a development install with test data)
- PHP 8.2+ with `curl` and `openssl`
- OpenEMR reachable over HTTPS. A self-signed certificate is fine.
- An OpenEMR login. Use an administrator if you want every write route to pass its ACL check.

## 2. Place the folder

The Explorer loads `../../interface/globals.php`, so it has to sit **two levels below the OpenEMR
root**. The usual place is `devtools/`:

```bash
cd /path/to/openemr
mkdir -p devtools
git clone https://github.com/sjpadgett/oe-module-api-explorer.git devtools/oe-module-api-explorer
```

Any two-level path works (`contrib/api-explorer`, for example). The Explorer reads its own folder
names from disk to build its URLs.

With the OpenEMR Docker dev environment, clone it inside the mounted source tree the same way.
The web server user must be able to write to `clients_keys/`:

```bash
chmod 775 devtools/oe-module-api-explorer/clients_keys
```

## 3. Enable the APIs in OpenEMR

Admin → Config → Connectors (labels vary slightly between versions):

| Setting | Needed for |
|---|---|
| Site Address (required for OAuth2 and FHIR) | Everything. Set it to the URL you browse OpenEMR at, with no trailing slash. |
| Enable OpenEMR Standard REST API | The Standard API mode |
| Enable OpenEMR Standard FHIR REST API | FHIR mode, writes, Scope Lab |
| Enable OpenEMR FHIR System Scopes | The JWT client, bulk export, Scope Lab client-credentials runs |
| Enable OAuth2 Password Grant | Only the Scope Lab's password grant. Leave it off otherwise. |

Save, then log out and back in.

## 4. Configure your sites

Skip this if OpenEMR is at `https://localhost/openemr`.

```bash
cd devtools/oe-module-api-explorer
cp config.local.sample.php config.local.php
```

Edit `config.local.php`:

```php
return [
    'sites' => [
        'localhost' => 'https://localhost/openemr',
        'docker'    => 'https://localhost:9300',
    ],
    'default_site' => 'docker',
];
```

The base URL is whatever comes before `/apis/default/fhir`. `config.local.php` is git-ignored, so
your server names stay out of commits and pulls never touch them. The sample file documents every
option.

## 5. Register the clients

Open:

```
https://<your-openemr>/devtools/oe-module-api-explorer/oeApiExplorer.php
```

Choose the **Site**, then click **Register Clients**. For that site it:

1. creates an RSA key pair and JWKS in `clients_keys/`
2. reads the scopes the server publishes and drops any the Explorer wants but the server doesn't offer (they're listed in the output)
3. registers four clients: `JWT`, `confidential`, `public` and `smart`
4. saves each client's id, secret and scopes to `clients_keys/client_<site>_<type>.json`
5. enables the clients in the database

Running it again replaces the clients, which is how a scope change takes effect. Repeat for each
site you use.

Registration runs from the browser. The command line isn't supported yet.

## 6. First request

1. Client **Confidential**, Grant **Authorization Code**, API **FHIR**, Resource **Patient**
2. **Fetch**
3. Log in to OpenEMR and approve the scopes
4. The response appears under the Request panel

Then try the other panels:

- **FHIR writes**: click **Load**, then **Run all resources**. On an empty install click
  **Create missing fixtures** first. See [FHIR_WRITES.md](FHIR_WRITES.md).
- **Scope Lab**: click **Discover scopes**, choose a grant, tick profiles, **Run selected**. See
  [SCOPE_LAB.md](SCOPE_LAB.md).
- **Bulk $export**: switch the Request panel to the **JWT** client and fetch once, then **List**
  groups and **Start export**.
- **SMART launch**: see [SMART_TESTING.md](SMART_TESTING.md).

## Special setups

**Explorer and OpenEMR on different installs.** The Explorer can run inside one OpenEMR and test
another. Name that site `remote-<something>`. Register Clients can't reach the other database, so
it won't delete old clients there or enable new ones: enable them on that server under
Admin → System → API Clients.

**Reverse proxy or mapped port.** If the redirect URL the Explorer builds is wrong, set it:

```php
'app_url' => 'https://dev.example.org/devtools/oe-module-api-explorer',
```

**Shared development server.** The Explorer answers only local and private-network addresses by
default. To open it up, set `'allow_remote' => true`, and read [SECURITY.md](SECURITY.md) first.

**JWKS by URL.** `'use_keys_file' => true` registers a `jwks_uri` instead of an inline key set.
OpenEMR must be able to fetch `clients_keys/<site>_jwks.json` over HTTPS with a certificate it
trusts, which rules out most self-signed setups. Inline is the default for that reason.

**nginx.** `clients_keys/.htaccess` only protects the folder on Apache. Add a `location` block
that denies `clients_keys/` except `*_jwks.json`.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Blank page or 500 on `oeApiExplorer.php` | The folder isn't two levels below the OpenEMR root, so `globals.php` can't be found. |
| `403 OpenEMR API Explorer only answers local…` | You're browsing from a public address. Set `allow_remote` in `config.local.php`. |
| Register Clients: `invalid_scope` | Rare now that unpublished scopes are dropped. It means discovery failed (the output says so). Check the site URL and that the FHIR API is enabled. |
| Register Clients: connection refused / SSL error | The site's base URL is wrong, or PHP can't reach it from the web server (a container needs the container-side hostname). |
| Token: `invalid_client` or "client is not enabled" | The client is disabled. Enable it under Admin → System → API Clients. Always the case for `remote-` sites. |
| Redirect comes back to the wrong host | Site Address in OpenEMR doesn't match the URL you browse, or you need `app_url`. |
| Every call is 401 after a scope change | Scopes are fixed at registration. Run Register Clients again, then **Clear Session** and fetch. |
| JWT client: `invalid_client` on token | System scopes aren't enabled in OpenEMR, or the key pair was regenerated without re-registering. |
| FHIR writes: "scope missing" | The token in session isn't the Confidential client's. Fetch once with Confidential + Authorization Code. |
| FHIR writes: resources skipped for "unresolved context" | The database has no patient, facility, encounter and so on. Click **Create missing fixtures**. |
| Write returns 401 with a valid scope | The logged-in user lacks the ACL for that route. The panel shows the ACL per resource. |
| Scope Lab: "Missing or invalid CSRF token" | The session expired. Reload the Explorer page. |
| Site dropdown shows a site you removed | **Clear Session**. |

To start completely clean: delete everything in `clients_keys/` except `.htaccess`, `.gitkeep`
and `README.md`, click **Clear Session**, and register again.

## Updating

```bash
cd devtools/oe-module-api-explorer
git pull
```

Then run Register Clients again if [CHANGELOG.md](CHANGELOG.md) mentions scope changes.

## Removing

Delete the folder. To remove the clients too, first use **Clear + delete lab clients** in the
Scope Lab, and delete the four `<site> …` clients under Admin → System → API Clients.
