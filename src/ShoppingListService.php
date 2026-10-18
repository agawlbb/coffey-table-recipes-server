<?php
declare(strict_types=1);

final class ShoppingListService
{
    public const DEPARTMENTS = ['', 'Other', 'Produce', 'Dairy & eggs', 'Meat & seafood', 'Bakery', 'Pantry', 'Frozen', 'Deli', 'Drinks', 'Household'];
    public function __construct(private PDO $pdo, private HouseholdService $households) {}

    public static function validate(array $body, ?array $departments = null): array
    {
        if (!is_int($body['revision'] ?? null) || $body['revision'] < 0
            || !is_array($body['items'] ?? null) || array_values($body['items']) !== $body['items'] || count($body['items']) > 500) {
            throw new HttpError(422, 'invalid_list', 'Choose up to 500 shopping items.');
        }
        $items = []; $ids = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $item['id']) || isset($ids[$item['id']])
                || !is_string($item['name'] ?? null) || trim($item['name']) === '' || mb_strlen($item['name']) > 200
                || !is_bool($item['checked'] ?? null) || !in_array($item['department'] ?? null, $departments === null ? self::DEPARTMENTS : array_merge([''], $departments), true)) {
                throw new HttpError(422, 'invalid_item', 'Enter an item of 1 to 200 characters with a valid department.');
            }
            $ids[$item['id']] = true;
            $items[] = ['id'=>$item['id'], 'name'=>trim($item['name']), 'checked'=>$item['checked'], 'department'=>$item['department']];
        }
        return $items;
    }

    public function read(string $user, string $household): array
    {
        $this->households->membership($user, $household);
        $q = $this->pdo->prepare('SELECT revision, items FROM shopping_lists WHERE household_id=?');
        $q->execute([$household]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $stored = $row ? json_decode($row['items'], true, 16, JSON_THROW_ON_ERROR) : [];
        // Older rows contain the item array directly; the envelope adds household
        // department settings without a schema migration or changing old clients.
        return ['revision'=>$row ? (int)$row['revision'] : 0,
            'items'=>$stored['items'] ?? $stored,
            'departments'=>$stored['departments'] ?? array_merge(array_slice(self::DEPARTMENTS, 2), ['Other'])];
    }

    public function save(string $user, string $household, array $body): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize household edits, including the first list creation.
            $q = $this->pdo->prepare('SELECT id FROM households WHERE id=? FOR UPDATE');
            $q->execute([$household]);
            $old = $this->read($user, $household);
            if ($old['revision'] !== $body['revision']) throw new HttpError(409, 'shopping_conflict', 'The shopping list changed. Review the updated list and try again.');
            $departments = array_key_exists('departments', $body) ? $body['departments'] : $old['departments'];
            if (!is_array($departments) || array_values($departments) !== $departments || count($departments) > 50) {
                throw new HttpError(422, 'invalid_departments', 'Choose up to 50 departments.');
            }
            $names = [];
            foreach ($departments as $department) {
                if (!is_string($department) || trim($department) !== $department || $department === '' || mb_strlen($department) > 60
                    || in_array(mb_strtolower($department), $names, true)) {
                    throw new HttpError(422, 'invalid_departments', 'Use unique department names of 1 to 60 characters.');
                }
                $names[] = mb_strtolower($department);
            }
            $items = self::validate($body, $departments);
            $revision = $old['revision'] + 1;
            $json = json_encode(['items'=>$items, 'departments'=>$departments], JSON_THROW_ON_ERROR);
            if ($old['revision'] === 0) {
                $this->pdo->prepare('INSERT INTO shopping_lists (household_id,revision,items) VALUES (?,?,?)')->execute([$household,$revision,$json]);
            } else {
                $this->pdo->prepare('UPDATE shopping_lists SET revision=?,items=? WHERE household_id=?')->execute([$revision,$json,$household]);
            }
            $this->pdo->commit();
            return ['revision'=>$revision, 'items'=>$items, 'departments'=>$departments];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
