<?php

//require_once 'config.php';
?>
<h5>🔧 Available Configured Sites. (Edit in config.php)</h5>
Remotes need work. Could be proxie issues. May need to set override in Config connector for proxy!
<pre>
    <code><?php echo print_r($apiSites, true); ?></code>
</pre>
<hr />
<p>This example project demonstrates how to interact with the OpenEMR OAuth2, FHIR, and Standard REST APIs using various OAuth2 grant types. It provides a working reference implementation for developers building apps that integrate with OpenEMR’s API infrastructure.</p>

<ul>
    <li>Educational reference for OAuth2 client registration, token handling, and API use</li>
    <li>Uses OpenEMR-compatible OpenSSL RSA logic</li>
    <li>Developed by Jerry Padgett for the OpenEMR community</li>
</ul>

<h5>📌 Project Purpose</h5>
<p>Designed for developers to:</p>
<ul>
    <li>Register JWT, Confidential, or Public clients</li>
    <li>Authenticate via OAuth2 grant types</li>
    <li>Explore OpenEMR's FHIR and standard APIs</li>
</ul>

<h5>🚀 Features</h5>
<ul>
    <li>Authorization Code + PKCE, Client Credentials, JWT, and Refresh Token flows</li>
    <li>JWT key generation and JWKS setup (inline or file-based)</li>
    <li>Smart UI syncs client + grant selections</li>
    <li>Scope-based resource population</li>
</ul>

<h5>JWKS Modes</h5>
<ul>
    <li><strong>Inline (default):</strong> No SSL needed, easy to configure</li>
    <li><strong>File-based:</strong> Requires valid cert, but allows JWKS URI</li>
</ul>

<h5>🔧 Current Configuration Values</h5>
<table class="table table-sm table-bordered bg-light">
    <thead>
    <tr><th>Config Key</th><th>Value</th></tr>
    </thead>
    <tbody>
    <tr><td>JWKS_LOCATION_URL</td><td><?= $GLOBALS['ApiConfig']['JWKS_LOCATION_URL'] ?? 'null' ?></td></tr>
    <tr><td>AUTHORIZATION_ENDPOINT</td><td><?= $GLOBALS['ApiConfig']['AUTHORIZATION_ENDPOINT'] ?></td></tr>
    <tr><td>REDIRECT_URI</td><td><?= $GLOBALS['ApiConfig']['REDIRECT_URI'] ?></td></tr>
    <tr><td>TOKEN_ENDPOINT</td><td><?= $GLOBALS['ApiConfig']['TOKEN_ENDPOINT'] ?></td></tr>
    <tr><td>FHIR_SERVER_URL</td><td><?= $GLOBALS['ApiConfig']['FHIR_SERVER_URL'] ?></td></tr>
    <tr><td>API_SERVER_URL</td><td><?= $GLOBALS['ApiConfig']['API_SERVER_URL'] ?></td></tr>
    <tr><td>REGISTER_CLIENT_ENDPOINT</td><td><?= $GLOBALS['ApiConfig']['REGISTER_CLIENT_ENDPOINT'] ?></td></tr>
    </tbody>
</table>
<h5>🧪 Usage Instructions</h5>
<ol>
    <li>Edit <code>config.php</code> — (Only if needed)</li>
    <li>Run <code>client_register.php</code> (CLI or browser)</li>
    <li>Explore with <code>oeApiExplorer.php</code></li>
</ol>

<h5>🔐 Token Handling</h5>
<ul>
    <li>Tokens auto-refresh using <code>refresh_token</code></li>
    <li>JWT auth signs with <code>private.pem</code></li>
</ul>

<h5>📁 File Overview</h5>
<ul>
    <li><strong>client_register.php</strong> — client & key registration</li>
    <li><strong>oeApiExplorer.php</strong> — browser-based UI</li>
    <li><strong>oauth_client.php</strong> — handles grant flows</li>
    <li><strong>src/JwkService.php</strong> — PEM/JWKS generation</li>
</ul>

<h5>🔧 Requirements</h5>
<ul>
    <li>OpenEMR 7+</li>
    <li>PHP 7.4+ with OpenSSL</li>
    <li>SSL only required if using <code>jwks_uri</code></li>
</ul>

<h5>🙌 Contributing</h5>
<p>PRs welcome — for the OpenEMR developer community.</p>

<h5>📜 License</h5>
<p>This code is provided as-is for education and learning. Not an official OpenEMR product.</p>

<p>© 2025 Jerry Padgett — <a href="mailto:sjpadgett@gmail.com">sjpadgett@gmail.com</a></p>
<h5>🌐 Multi-Site and Grant-Aware Context</h5>
<ul>
    <li>Select from multiple OpenEMR sites (e.g., localhost, docker, remote)</li>
    <li>Each site + grant pair stores a unique client and key set</li>
    <li>Switching sites updates all OAuth2 and API endpoint paths</li>
</ul>

<h5>⚙️ Dynamic Configuration</h5>
<ul>
    <li>Endpoints are stored in <code>$GLOBALS['ApiConfig']</code></li>
    <li>Paths auto-update when site or grant changes</li>
    <li>JWKS URI and redirect URIs adjust in real time</li>
</ul>

<h5>📁 clients_keys Directory</h5>
<ul>
    <li>Stores all generated client JSON, PEM keys, and JWKS</li>
    <li>Directory is created if missing — no manual setup required</li>
</ul>

<h5>🛡️ Session Handling</h5>
<ul>
    <li>Sessions explicitly started in all scripts</li>
    <li>Preserves selected site, grant, and tokens across redirects</li>
</ul>
