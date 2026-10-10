<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$checks = 0;
function checkAdmin(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function rejectAdmin(callable $action, int $status): void {
    try { $action(); } catch (HttpError $error) { checkAdmin($error->status === $status, 'Unexpected rejection'); return; }
    throw new RuntimeException('Expected rejection');
}
$config = configuration();
if ($config['environment'] !== 'development') throw new RuntimeException('Development only.');
$db = $config['database'];
$root = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'coffey_admin_test_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
try {
    $config['database']['name'] = $name;
    $pdo = database($config);
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        foreach (explode(';', file_get_contents($file)) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
    }
    $ident = static fn($uid, $email, $verified = true) => ['uid' => $uid, 'name' => $uid, 'email' => $email, 'email_verified' => $verified];
    $h = new HouseholdService($pdo);
    $unverified = $h->bootstrap($ident('admin', 'admin@example.test', false));
    checkAdmin($unverified['account_role'] === 'user', 'Unverified email granted administration');
    $actor = $h->bootstrap($ident('admin', 'admin@example.test'));
    checkAdmin($actor['account_role'] === 'administrator', 'Administrator missing');
    $target = $h->bootstrap($ident('target', 'target@example.test'));
    $other = $h->bootstrap($ident('other', 'other@example.test'));
    $table = $h->create($target['id'], 'Shared table', 'Target');
    $pdo->prepare("INSERT INTO household_memberships (household_id, user_id) VALUES (?, ?)")->execute([$table['id'], $other['id']]);
    $person = $h->addPerson($target['id'], $table['id'], 'Guest');
    $pdo->prepare("INSERT INTO household_invitations (household_id, person_id, invited_by_user_id, email, expires_at) VALUES (?, ?, ?, 'guest@example.test', UTC_TIMESTAMP() + INTERVAL 1 DAY)")->execute([$table['id'], $person['id'], $target['id']]);
    $pdo->prepare("INSERT INTO recipes (household_id, title, created_by_user_id, updated_by_user_id) VALUES (?, 'Keep me', ?, ?)")->execute([$table['id'], $target['id'], $target['id']]);
    $pdo->prepare("INSERT INTO cooking_events (household_id, cooked_at, recorded_by_user_id) VALUES (?, UTC_TIMESTAMP(), ?)")->execute([$table['id'], $target['id']]);
    $calls = []; $fail = false;
    $service = new AdministrationService($pdo, static function ($uid) use (&$calls, &$fail): void {
        $calls[] = $uid;
        if ($fail) throw new HttpError(503, 'firebase_unavailable', 'Firebase unavailable');
    });
    rejectAdmin(static fn() => $service->users($other['id']), 403);
    rejectAdmin(static fn() => $service->delete($other['id'], $target['id'], true), 403);
    rejectAdmin(static fn() => $service->delete($actor['id'], $target['id'], false), 422);
    rejectAdmin(static fn() => $service->delete($actor['id'], $actor['id'], true), 409);
    rejectAdmin(static fn() => $service->delete($actor['id'], '999999', true), 404);
    checkAdmin($calls === [], 'Rejected request reached Firebase');
    checkAdmin(count($service->users($actor['id'])) === 3, 'User listing incomplete');
    $fail = true;
    rejectAdmin(static fn() => $service->delete($actor['id'], $target['id'], true), 503);
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'Remote failure deleted SQL user');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_invitations')->fetchColumn() === 1, 'Remote failure removed invitation');
    checkAdmin($pdo->query('SELECT created_by_user_id FROM recipes')->fetchColumn() == $target['id'], 'Remote failure lost recipe attribution');
    $fail = false;
    $service->delete($actor['id'], $target['id'], true);
    checkAdmin($calls === ['target', 'target'], 'Wrong Firebase UID');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2, 'SQL user remains');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE user_id IS NOT NULL')->fetchColumn() === 0, 'Linked person remains');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_memberships')->fetchColumn() === 1, 'Wrong memberships deleted');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_invitations')->fetchColumn() === 0, 'Invitation remains');
    checkAdmin($pdo->query('SELECT title FROM recipes')->fetchColumn() === 'Keep me', 'Shared recipe deleted');
    checkAdmin($pdo->query('SELECT created_by_user_id FROM recipes')->fetchColumn() === null, 'Account attribution remains');
    checkAdmin($pdo->query('SELECT recorded_by_user_id FROM cooking_events')->fetchColumn() === null, 'History attribution remains');
    $guarded = new HouseholdService($pdo, static function ($uid): void { throw new HttpError(401, 'account_deleted', 'Deleted'); });
    rejectAdmin(static fn() => $guarded->bootstrap($ident('target', 'target@example.test')), 401);
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2, 'Deleted token recreated user');
    $invitee = $h->bootstrap($ident('invitation-only', 'invitation-only@example.test'));
    $ownerTable = $h->create($actor['id'], 'Invitation table', 'Administrator');
    $unlinked = $h->addPerson($actor['id'], $ownerTable['id'], 'Invitation person');
    $sameName = $h->addPerson($actor['id'], $ownerTable['id'], 'Invitation person');
    $pdo->prepare("INSERT INTO household_invitations (household_id, person_id, invited_by_user_id, email, status, expires_at, responded_by_user_id) VALUES (?, ?, ?, ?, 'accepted', UTC_TIMESTAMP() + INTERVAL 1 DAY, ?)")->execute([$ownerTable['id'], $unlinked['id'], $actor['id'], $invitee['email'], $invitee['id']]);
    $fail = true;
    rejectAdmin(static fn() => $service->delete($actor['id'], $invitee['id'], true), 503);
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE id = ' . (int) $unlinked['id'])->fetchColumn() === 1, 'Remote failure removed invitation person');
    $fail = false;
    $service->delete($actor['id'], $invitee['id'], true);
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE id = ' . (int) $unlinked['id'])->fetchColumn() === 0, 'Unlinked invitation person remains');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE id = ' . (int) $sameName['id'])->fetchColumn() === 1, 'Unrelated same-name person deleted');
    $accepted = $h->bootstrap($ident('accepted', 'accepted@example.test'));
    $acceptedPerson = $h->addPerson($actor['id'], $ownerTable['id'], 'Accepted person');
    $invitations = new InvitationService($pdo, $h, static fn($email) => null, static function ($email, $text): void {}, 'https://example.test/app/');
    $invitation = $invitations->invite($actor, $ownerTable['id'], $acceptedPerson['id'], $accepted['email']);
    $invitations->respond($accepted, $invitation['id'], 'accept');
    checkAdmin($pdo->query('SELECT user_id FROM household_people WHERE id = ' . (int) $acceptedPerson['id'])->fetchColumn() == $accepted['id'], 'Accepted invitation did not link person');
    $service->delete($actor['id'], $accepted['id'], true);
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE id = ' . (int) $acceptedPerson['id'])->fetchColumn() === 0, 'Add-invite-accept-delete left table person');
    checkAdmin((int) $pdo->query('SELECT COUNT(*) FROM household_people WHERE id = ' . (int) $person['id'])->fetchColumn() === 1, 'Deleting inviter removed unrelated guest');
    $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = ?')->execute([$actor['id']]);
    rejectAdmin(static fn() => $service->users($actor['id']), 403);
    echo "$checks administrator checks passed.\n";
} finally { $root->exec("DROP DATABASE `$name`"); }
