<?php
declare(strict_types=1);

final class AdministrationService
{
    public function __construct(private PDO $pdo, private $deleteFirebase, private $listFirebase = null) {}

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
        $rows = $this->pdo->query('SELECT id, firebase_uid, display_name, email, email_verified, account_role, disabled FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(static function (array $row): array {
            $row['id'] = (string) $row['id'];
            $row['disabled'] = (bool) $row['disabled'];
            $row['email_verified'] = (bool) $row['email_verified'];
            return $row;
        }, $rows);
        if ($this->listFirebase !== null) {
            $known = array_column($rows, null, 'firebase_uid');
            foreach (($this->listFirebase)() as $account) {
                if (isset($known[$account['uid']])) continue;
                $rows[] = ['id' => 'firebase:' . $account['uid'], 'firebase_uid' => $account['uid'], 'display_name' => $account['display_name'], 'email' => $account['email'], 'email_verified' => $account['email_verified'], 'disabled' => $account['disabled'], 'account_role' => 'user', 'firebase_only' => true];
            }
        }
        return $rows;
    }

    public function people(string $actorId): array
    {
        $this->authorize($actorId);
        $rows = $this->pdo->query('SELECT p.id, p.display_name, p.household_id, h.name AS household_name FROM household_people p JOIN households h ON h.id = p.household_id WHERE p.user_id IS NULL ORDER BY h.name, p.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (string) $row['id'];
            $row['household_id'] = (string) $row['household_id'];
        }
        return $rows;
    }

    public function deletePerson(string $actorId, string $personId, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $q->execute([$actorId]);
            $this->authorize($actorId);
            $q = $this->pdo->prepare('SELECT user_id FROM household_people WHERE id = ? FOR UPDATE');
            $q->execute([$personId]);
            $person = $q->fetch(PDO::FETCH_ASSOC);
            if (!$person) throw new HttpError(404, 'person_not_found', 'Person not found.');
            if ($person['user_id'] !== null) throw new HttpError(409, 'person_linked', 'This person now has a linked account. Refresh and delete that account instead.');
            $this->pdo->prepare('DELETE FROM household_invitations WHERE person_id = ?')->execute([$personId]);
            $this->pdo->prepare('DELETE FROM household_people WHERE id = ?')->execute([$personId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function deleteFirebaseOnly(string $actorId, string $uid, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        // A now-registered account must use the complete SQL cleanup path.
        $q = $this->pdo->prepare('SELECT id FROM users WHERE firebase_uid = ?');
        $q->execute([$uid]);
        $id = $q->fetchColumn();
        if ($id !== false) { $this->delete($actorId, (string) $id, true); return; }
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $q->execute([$actorId]); $this->authorize($actorId);
            $q = $this->pdo->prepare('SELECT id FROM users WHERE firebase_uid = ? FOR UPDATE');
            $q->execute([$uid]);
            if ($q->fetchColumn() !== false) throw new HttpError(409, 'account_registered', 'Account setup completed. Refresh and retry deletion.');
            $email = null;
            if ($this->listFirebase !== null) {
                foreach (($this->listFirebase)() as $account) {
                    if ($account['uid'] === $uid) { $email = $account['email']; break; }
                }
            }
            if ($email !== null && $email !== '') {
                $q = $this->pdo->prepare('SELECT DISTINCT p.id FROM household_people p JOIN household_invitations i ON i.person_id = p.id WHERE p.user_id IS NULL AND i.email = ?');
                $q->execute([$email]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $personId) {
                    $this->pdo->prepare('DELETE FROM household_invitations WHERE person_id = ?')->execute([$personId]);
                    $this->pdo->prepare('DELETE FROM household_people WHERE id = ? AND user_id IS NULL')->execute([$personId]);
                }
                $this->pdo->prepare('DELETE FROM household_invitations WHERE email = ?')->execute([$email]);
            }
            // The unique-index gap lock blocks concurrent account registration.
            ($this->deleteFirebase)($uid);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function delete(string $actorId, string $targetId, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
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
            // Resolve invitation-associated people before deleting the evidence that
            // associates an unlinked table entry with this account. Never match names
            // or remove an entry already linked to a different account.
            $people = $this->pdo->prepare('SELECT DISTINCT p.id FROM household_people p LEFT JOIN household_invitations i ON i.household_id = p.household_id AND i.person_id = p.id WHERE p.user_id = ? OR (p.user_id IS NULL AND (i.email = ? OR (i.status = \'accepted\' AND i.responded_by_user_id = ?)))');
            $people->execute([$targetId, $target['email'], $targetId]);
            $personIds = $people->fetchAll(PDO::FETCH_COLUMN);
            if ($personIds !== []) {
                $placeholders = implode(',', array_fill(0, count($personIds), '?'));
                $this->pdo->prepare("DELETE FROM household_invitations WHERE person_id IN ($placeholders)")->execute($personIds);
                $this->pdo->prepare("DELETE FROM household_people WHERE id IN ($placeholders)")->execute($personIds);
            }
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
