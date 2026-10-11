<?php
declare(strict_types=1);

/** Signed capability restricted to one recipe. IDs alone cannot grant access. */
final class RecipeShare
{
    public function __construct(private string $key) {
        if (strlen($key) < 32) throw new HttpError(503, 'sharing_unavailable', 'Recipe sharing is not configured yet.');
    }

    public function token(string $user, string $household, string $recipe): string {
        $payload = bin2hex(json_encode([$user, $household, $recipe], JSON_THROW_ON_ERROR));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->key);
    }

    public function resolve(string $token): array {
        if (strlen($token) > 512 || !preg_match('/^([a-f0-9]+)\.([a-f0-9]{64})$/D', $token, $m)
            || !hash_equals(hash_hmac('sha256', $m[1], $this->key), $m[2])) {
            throw new HttpError(404, 'share_not_found', 'This recipe link is unavailable.');
        }
        $ids = json_decode(hex2bin($m[1]), true);
        if (!is_array($ids) || count($ids) !== 3) throw new HttpError(404, 'share_not_found', 'This recipe link is unavailable.');
        foreach ($ids as $id) if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id)) throw new HttpError(404, 'share_not_found', 'This recipe link is unavailable.');
        return $ids;
    }
}
