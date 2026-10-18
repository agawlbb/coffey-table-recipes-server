<?php
declare(strict_types=1);

/** Household-owned integration credentials; stored hashes never authenticate. */
final class HomeAssistantConnections
{
    public function __construct(private string $directory) {}

    private function path(string $token): string
    {
        if (!preg_match('/^ctrha_[a-f0-9]{64}$/D', $token)) throw new HttpError(401, 'invalid_connection', 'Reconnect Home Assistant.');
        return $this->directory . '/' . hash('sha256', $token) . '.json';
    }

    public function create(string $household): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Connection storage unavailable.');
        $token = 'ctrha_' . bin2hex(random_bytes(32));
        $path = $this->path($token);
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Connection storage unavailable.');
        try {
            chmod($path, 0600);
            $data = json_encode(['household_id'=>$household, 'created_at'=>gmdate(DATE_ATOM), 'scope'=>'household_integration'], JSON_THROW_ON_ERROR);
            if (fwrite($handle, $data) !== strlen($data)) { unlink($path); throw new RuntimeException('Connection storage unavailable.'); }
        } finally { fclose($handle); }
        return ['token'=>$token, 'household_id'=>$household];
    }

    public function household(string $token): string
    {
        $path = $this->path($token);
        if (!is_file($path)) throw new HttpError(401, 'invalid_connection', 'Reconnect Home Assistant.');
        $data = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        return $data['household_id'];
    }

    public function revoke(string $token): void
    {
        $path = $this->path($token);
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot revoke connection.');
    }

    public function list(string $household): array
    {
        $result = [];
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            $data = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            if ($data['household_id'] === $household) $result[] = ['id'=>basename($path, '.json'), 'created_at'=>$data['created_at']];
        }
        return $result;
    }

    public function revokeById(string $household, string $id): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new HttpError(404, 'connection_not_found', 'Connection not found.');
        $path = $this->directory.'/'.$id.'.json';
        if (!is_file($path)) throw new HttpError(404, 'connection_not_found', 'Connection not found.');
        $data = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if ($data['household_id'] !== $household) throw new HttpError(404, 'connection_not_found', 'Connection not found.');
        if (!unlink($path)) throw new RuntimeException('Cannot revoke connection.');
    }
}
