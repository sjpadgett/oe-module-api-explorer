# OpenEMR FHIR/API Client Explorer Web App

This example project demonstrates how to interact with the OpenEMR OAuth2, FHIR, and Standard REST APIs using various OAuth2 grant types. It provides a working reference implementation for developers building apps that integrate with OpenEMR’s API infrastructure.

- The code is designed to be educational, showcasing best practices for OAuth2 client registration, token management, and API interaction.
- I used AI ChatGPT to help refine the code, ensuring it adheres to OpenEMR standards including help creating this README. Sure could have used it 40 years ago! 😄
- I've dedicated this project to the OpenEMR community to help you learn and experiment with OAuth2 authentication, FHIR resources, and standard API endpoints.

---

## 📌 Project Purpose

This explorer is built for intermediate developers to learn and experiment with OpenEMR's OAuth2-secured APIs — including FHIR and standard (non-FHIR) endpoints. The goal is to enable developers to:

- Register new public, confidential, or JWT clients
- Authenticate using several OAuth2 grant types
- Query real-time API resources like `Patient`, `Encounter`, etc.
- Learn by example for future integration projects

---

## 🚀 Features

### ✅ OAuth2 Grant Types Supported
- **Authorization Code** (with PKCE for public clients)
- **Client Credentials** (for system-to-system apps)
- **Refresh Token** (auto-refreshes access token when expired)
- **JWT Client Credentials** (`client_secret_post`) — Supports both inline key-pair and JWKS URI

### ✅ Client Registration
- Automatically registers `confidential`, `public`, or `JWT` clients with OpenEMR
- JWT registration:
  - Auto-generates RSA key pair (`private.pem`, `public.pem`)
  - Uses either embedded `jwks` or `jwks_uri`, depending on config
  - JWKS and PEM paths are saved in `client_JWT.json`
- No need to go to the command line or use shell commands — keygen uses OpenEMR-compatible PHP OpenSSL
- CLI and browser support (`--regen` or `?regen=1`)
- Smart fallback: If `use_keys_file` is false, SSL is not required and `jwks_uri` is omitted

### ✅ API Modes
- **FHIR API**: Uses OpenEMR’s `/apis/default/fhir`
- **Standard API**: Uses `/apis/default/api`

### ✅ UI Behavior
- Grant type is automatically updated when client type changes:
  - `JWT` forces `client_credentials`
  - `public` forces `authorization_code`
  - `confidential` supports all three (`authorization_code`, `client_credentials`, `refresh_token`)
- Resource list dynamically updates based on selected client/scopes

---

## ⚙️ Dynamic Configuration with `$GLOBALS['ApiConfig']`

Instead of using `define()`, the explorer uses a dynamic global configuration array that adjusts automatically when switching sites or grant types.

```php
$GLOBALS['ApiConfig'] = [
  'JWKS_LOCATION_URL'        => "{$base_path}/clients_keys/{$site}_jwks.json",
  'AUTHORIZATION_ENDPOINT'   => "{$base_path}/oauth2/default/authorize",
  'TOKEN_ENDPOINT'           => "{$base_path}/oauth2/default/token",
  'LOGOUT_REDIRECT_URI'      => "{$base_path}/oauth2/default/logout.php",
  'REGISTER_CLIENT_ENDPOINT' => "{$base_path}/oauth2/default/registration",
  'FHIR_SERVER_URL'          => "{$base_path}/apis/default/fhir",
  'API_SERVER_URL'           => "{$base_path}/apis/default/api",
  'REDIRECT_URI'             => "{$app_path}/oeApiExplorer.php"
];
```

Use the values anywhere via:

```php
$GLOBALS['ApiConfig']['FHIR_SERVER_URL']
```


**💡 Notes:**
- If `$use_keys_file = true`: JWKS file must be served via HTTPS with a valid certificate
- If `false`: Uses inline JWKS, avoids SSL issues and file permissions

---

## 🧪 Usage Instructions

1. **Edit `config.php`**
   - Set your OpenEMR domain, API paths, and `$use_keys_file` option

2. **Run `client_register.php`**
   - Registers JWT, confidential, and public clients
   - CLI:  
     ```bash
     php client_register.php --regen
     ```
   - Browser:  
     ```
     https://your-openemr/modules/oe-module-api-explorer/client_register.php?regen=1
     ```

3. **Explore via `oeApiExplorer.php`**
   - Choose client type → grant type auto syncs
   - Select FHIR or Standard API
   - Pick a resource (e.g. Patient, Encounter)

---

## 🔐 Token Handling

- Tokens are stored in `$_SESSION`
- Automatically refreshed when `refresh_token` is present
- JWT clients use `lcobucci/jwt` to sign requests with generated `private.pem`
- JWKS is exposed via URI or embedded in client registration depending on `$use_keys_file`

---

## 📁 File Overview

| File                      | Purpose                                                  |
|---------------------------|-----------------------------------------------------------|
| `client_register.php`     | Registers clients and generates key pairs / JWKS         |
| `oeApiExplorer.php`       | UI for exploring API resources via interactive interface |
| `oauth_client.php`        | OAuth2 token exchange and refresh logic                  |
| `config.php`              | Defines environment-specific settings and toggle flags   |
| `src/JwkService.php`      | Generates RSA key pair and builds JWKS for JWT clients   |
| `client_*.json`           | Stores registered client credentials                     |

---

## 🔧 Requirements

- OpenEMR 7+ with OAuth2 and FHIR APIs enabled
- PHP 7.4+ / 8.x with OpenSSL extension
- MySQL or MariaDB backend
- HTTPS recommended for production and required if `$use_keys_file = true`

---

## 🙌 Contributing

PRs and feedback are welcome.  
This tool was created to empower the OpenEMR developer community.

---

© 2025 Jerry Padgett — [sjpadgett@gmail.com](mailto:sjpadgett@gmail.com)

## 📜 License

This project is a community-contributed example provided for educational purposes.  
It is not an official OpenEMR module. You are free to use, modify, and extend it as needed.
---

## 🌐 Multi-Site and Grant-Aware Context

This explorer now supports multiple OpenEMR sites and dynamically adjusts key behaviors:

- Site dropdown lets you target `localhost`, `docker`, or remote domains
- Each combination of site + grant type stores a unique client file:
  - `client_docker_client_credentials.json`
  - `clients_keys/docker_jwks.json`
- The explorer rebuilds paths and keys on-the-fly when switching context

## ⚙️ Dynamic Configuration with $GLOBALS['ApiConfig']

Static defines have been replaced with a runtime-safe global config array:

```php
$GLOBALS['ApiConfig']['FHIR_SERVER_URL'] = "{$base_path}/apis/default/fhir";
```

This allows `config.php` to be reloaded at any time with new `$base_path` or `$app_path`, and all references update live.

## 📁 Client Storage

- Clients and JWKS are stored in `/clients_keys`
- If missing, the directory is automatically created with secure permissions
- PEM keys and JWKS files are named per site

## 🛡️ Session Handling

- Sessions are started explicitly in all entry points
- Ensures state (`client`, `grant`, `site`, `tokens`) persists through the Auth Code redirect