<?php

namespace OpenEMR\Auth;

class JwkService
{
    private $dir;
    private $privateKeyPath;
    private $publicKeyPath;
    private $jwksPath;

    public function __construct(string $baseDir, $api_site = 'localhost')
    {
        $this->dir = rtrim($baseDir, '/');
        $this->privateKeyPath = $this->dir . "/clients_keys/{$api_site}_private.pem";
        $this->publicKeyPath = $this->dir . "/clients_keys/{$api_site}_public.pem";
        $this->jwksPath = $this->dir . "/clients_keys/{$api_site}_jwks.json";
        $this->api_site = $api_site;
    }

    /**
     * @param bool $regen
     * @return void
     */
    public function ensureKeys(bool $regen = false): void
    {
        if (!file_exists($this->privateKeyPath) || !file_exists($this->publicKeyPath) || $regen) {
            $config = ["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];
            $res = openssl_pkey_new($config);
            if (!$res) {
                throw new \RuntimeException("Failed to generate RSA key pair.");
            }
            openssl_pkey_export($res, $privKey);
            file_put_contents($this->privateKeyPath, $privKey);
            $this->out("Private key generated and saved to {$this->privateKeyPath}");
            $pubDetails = openssl_pkey_get_details($res);
            file_put_contents($this->publicKeyPath, $pubDetails['key']);
            $this->out("Public key generated and saved to {$this->publicKeyPath}");
        } else {
            $this->out("Using existing keys: {$this->privateKeyPath} and {$this->publicKeyPath}");
        }

        if (!file_exists($this->jwksPath) || $regen) {
            $details = openssl_pkey_get_details(openssl_pkey_get_public(file_get_contents($this->publicKeyPath)));
            $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
            $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');
            $kid = substr(sha1($n . $e), 0, 32);

            $jwks = ['keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $kid,
                'alg' => 'RS384',
                'n' => $n,
                'e' => $e
            ]]];
            file_put_contents($this->jwksPath, json_encode($jwks, JSON_PRETTY_PRINT));
            $this->out("JWKS generated and saved to {$this->jwksPath}");
        }
    }

    /**
     * @return array
     */
    public function loadJwks(): array
    {
        if (!file_exists($this->jwksPath)) {
            throw new \RuntimeException("JWKS file not found at {$this->jwksPath}");
        }
        return json_decode(file_get_contents($this->jwksPath), true);
    }

    /**
     * @param string $msg
     * @return void
     */
    public function out(string $msg): void
    {
        if (php_sapi_name() === 'cli') {
            echo $msg . "\n";
        } else {
            $msg = "→ [$this->api_site]" . $msg;
            echo nl2br(text($msg)) . "<br/>";
        }
        @ob_flush();
        @flush();
    }
}
