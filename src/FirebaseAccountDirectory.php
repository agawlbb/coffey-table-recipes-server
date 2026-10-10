<?php
declare(strict_types=1);

use Firebase\JWT\JWT;

final class FirebaseAccountDirectory
{
    private ?string $token = null;
    public function __construct(private array $config) {}

    public function lookup(string $email): ?array
    {
        return $this->lookupBy(['email' => [$email]]);
    }

    public function requireAccount(string $uid): void
    {
        $user = $this->lookupBy(['localId' => [$uid]]);
        if ($user === null || $user['disabled']) throw new HttpError(401, 'account_deleted', 'Please sign in again.');
    }

    public function delete(string $uid): void
    {
        // Missing accounts are successful retries after an interrupted SQL commit.
        if ($this->lookupBy(['localId' => [$uid]]) === null) return;
        try {
            $this->post('https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($this->config['firebase_project_id']) . '/accounts:delete',
                json_encode(['localId' => $uid], JSON_THROW_ON_ERROR),
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken()]);
        } catch (HttpError $error) {
            throw new HttpError(503, 'account_delete_unavailable', 'Firebase deletion could not be completed. Check the server deletion permission and try again.');
        }
    }

    private function accessToken(): string
    {
        if ($this->token !== null) return $this->token;
        $file = $this->config['firebase_service_account_file'];
        if ($file === '' || !is_file($file)) {
            throw new HttpError(503, 'account_lookup_unavailable', 'Invitation account lookup is not configured yet.');
        }
        $key = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if (($key['project_id'] ?? '') !== $this->config['firebase_project_id'] || empty($key['private_key']) || empty($key['client_email'])) {
            throw new HttpError(503, 'account_lookup_unavailable', 'Invitation account lookup is not configured correctly.');
        }
        // PHP 8.0 cannot use the current Google Auth library alongside JWT 7.
        // Follow Google's documented service-account OAuth flow using the installed JWT library.
        $now = time();
        $assertion = JWT::encode([
            'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/identitytoolkit',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 300,
        ], $key['private_key'], 'RS256');
        $token = $this->post('https://oauth2.googleapis.com/token',
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]),
            ['Content-Type: application/x-www-form-urlencoded']);
        if (empty($token['access_token'])) throw new HttpError(503, 'account_lookup_unavailable', 'Account lookup is temporarily unavailable.');
        return $this->token = $token['access_token'];
    }

    private function lookupBy(array $filter): ?array
    {
        $response = $this->post('https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($this->config['firebase_project_id']) . '/accounts:lookup',
            json_encode($filter, JSON_THROW_ON_ERROR),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken()]);
        $users = $response['users'] ?? [];
        if ($users === []) return null;
        $user = $users[0];
        if (!is_string($user['localId'] ?? null)) throw new HttpError(503, 'account_lookup_unavailable', 'Account lookup is temporarily unavailable.');
        return ['uid' => $user['localId'], 'disabled' => !empty($user['disabled'])];
    }

    private function post(string $url, string $body, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $data = is_string($response) ? json_decode($response, true) : null;
        // Do not log API responses containing authentication records or credentials.
        if ($status !== 200 || !is_array($data)) {
            throw new HttpError(503, 'account_lookup_unavailable', 'Account lookup is temporarily unavailable. Please try again later.');
        }
        return $data;
    }
}
