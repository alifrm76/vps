<?php
/**
 * Virtualizor Admin API Client (Lightweight)
 */
class VirtualizorAdminClient
{
    protected $host;
    protected $key;
    protected $pass;
    protected $port;
    protected $ssl;
    protected $verifySSL;

    public function __construct(string $host, string $key, string $pass, int $port = 4085, bool $ssl = true, bool $verifySSL = true)
    {
        $this->host = rtrim($host, '/');
        $this->key  = $key;
        $this->pass = $pass;
        $this->port = $port;
        $this->ssl  = $ssl;
        $this->verifySSL = $verifySSL;
    }

    public function request(string $act, array $post = [], array $get = [])
    {
        $scheme = $this->ssl ? 'https' : 'http';
        $base   = $scheme . '://' . $this->host . ':' . $this->port . '/index.php';

        $key8   = substr(bin2hex(random_bytes(4)), 0, 8);
        $apikey = $key8 . md5($this->pass . $key8);

        $query = array_merge([
            'act'    => $act,
            'api'    => 'json',
            'apikey' => $apikey,
        ], $get);

        $apidata = '';
        if (!empty($post)) $apidata = base64_encode(serialize($post));

        $url = $base . '?' . http_build_query($query);
        $ch  = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!$this->verifySSL) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $apidata ? ['apidata' => $apidata] : []);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . $err);
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            if (isset($data['title']) && $data['title'] === 'Single Sign On (SSO)' && !empty($data['url'])) {
                return ['url' => $data['url']];
            }
            return $data;
        }

        if (stripos($raw, 'http') === 0) {
            return trim($raw);
        }

        $unser = @unserialize($raw);
        if ($unser !== false && is_array($unser)) {
            return $unser;
        }

        throw new \RuntimeException("HTTP $http Unexpected response: " . substr($raw, 0, 512));
    }
}
