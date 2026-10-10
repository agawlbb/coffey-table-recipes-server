<?php
declare(strict_types=1);

final class AdministrationService
{
    public function __construct(private PDO $pdo, private $deleteFirebase) {}

    private function authorize(string $actorId): void
    {
        $query = $this->pdo->prepare('SELECT account_role, disabled FROM users WHERE id = ?');
        $query->execute([$actorId]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['disabled'] || $actor['account_role'] !== 'administrator') {
            throw new HttpError(403, 'administrator_required', 'Administrator access is required.');
        }
    }

    public function users(string $actorId): array
    {
        $this->authorize($actorId);
        $rows = $this->pdo->query('SELECT id, display_name, email, account_role, disabled FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $row['id'] = (string) $row['id'];
            $row['disabled'] = (bool) $row['disabled'];
            return $row;
        }, $rows);
    }

    public function delete(string $actorId, string $targetId, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        if ($actorId === $targetId) throw new HttpError(409, 'self_delete', 'You cannot delete your own administrator account.');
        $this->pdo->beginTransaction();
        try {
            // Lock the actor as well so account permission changes cannot race deletion.
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $lock->execute([$actorId, $targetId]);
            $lock->fetchAll();
            $this->authorize($actorId);
            $query = $this->pdo->prepare('SELECT firebase_uid, email FROM users WHERE id = ?');
            $query->execute([$targetId]);
            $target = $query->fetch(PDO::FETCH_ASSOC);
            if (!$target) throw new HttpError(404, 'user_not_found', 'User not found.');
            // Keep shared recipes and history, removing account attribution and linked people.
            foreach ([
                ['DELETE i FROM household_invitations i LEFT JOIN household_people p ON p.id = i.person_id WHERE i.invited_by_user_id = ? OR i.responded_by_user_id = ? OR p.user_id = ? OR i.email = ?', [$targetId, $targetId, $targetId, $target['email']]],
                ['UPDATE recipes SET created_by_user_id = NULL WHERE created_by_user_id = ?', [$targetId]],
                ['UPDATE recipes SET updated_by_user_id = NULL WHERE updated_by_user_id = ?', [$targetId]],
                ['UPDATE cooking_events SET recorded_by_user_id = NULL WHERE recorded_by_user_id = ?', [$targetId]],
                ['DELETE FROM household_people WHERE user_id = ?', [$targetId]],
                ['DELETE FROM household_memberships WHERE user_id = ?', [$targetId]],
                ['DELETE FROM users WHERE id = ?', [$targetId]],
            ] as [$sql, $values]) {
                $this->pdo->prepare($sql)->execute($values);
            }
            // SQL errors occur before the irreversible remote operation. Firebase failures
            // roll SQL back. If commit fails after Firebase succeeds, retry is safe.
            ($this->deleteFirebase)($target['firebase_uid']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
