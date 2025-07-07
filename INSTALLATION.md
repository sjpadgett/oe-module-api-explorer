## OpenEMR API Explorer - Installation Guide

## Purpose
Educational reference implementation for developers building apps that integrate with OpenEMR's OAuth2, FHIR, and Standard REST APIs.

## Features
- FHIR and Standard API support
- OAuth2 client registration (JWT, Confidential, Public clients)
- Multiple grant type support (Authorization Code + PKCE, Client Credentials, JWT Bearer, Refresh Token)
- Interactive API explorer with browser-based UI
- Multi-site configuration support
- Automatic JWT key generation and JWKS setup
- Smart UI that syncs client and grant selections
- Scope-based resource population
- Token auto-refresh functionality

## Requirements
- OpenEMR 7.02+
- PHP 8.1+ with OpenSSL extension
- SSL certificate (only required if using jwks_uri mode)

## Installation Steps
1. Clone or download the project to your OpenEMR modules directory
- Repo: https://github.com/sjpadgett/oe-module-api-explorer
- Ensure the directory structure is `/openemr/modules/oe-module-api-explorer/` or two levels above openemr root directory for pathing.
2. Navigate to `/openemr/modules/oe-module-api-explorer/`
3. Edit `config.php` if needed (default localhost configuration works for most setups)
4. Access the explorer at `oeApiExplorer.php`
5. Register clients from within the app:
    - Select desired API Site from dropdown
    - Click "Register Clients" button
    - Repeat for each site you want to use

## Quick Start
1. Open `oeApiExplorer.php` in your browser
2. Select API Site and click "Register Clients" (do this for each site you plan to use)
3. Select client type, grant type, API type, and resource
4. Click "Fetch" to test API calls

## Configuration
- Edit `config.php` to add additional OpenEMR sites
- Default supports localhost, docker, and remote demo sites
- JWKS can be served inline (default) or via file (requires SSL)

## Key Files
- `client_register.php` - Client and key registration
- `oeApiExplorer.php` - Main browser interface
- `oauth_client.php` - OAuth2 flow handlers
- `config.php` - Environment configuration
- `clients_keys/` - Generated client credentials and keys

## Links
- GitHub Repository: https://github.com/sjpadgett/oe-module-api-explorer
- Author: Jerry Padgett (sjpadgett@gmail.com)
- License: Educational use, not an official OpenEMR product

## Support
This is a community-contributed educational tool. For issues or contributions, use the GitHub repository.