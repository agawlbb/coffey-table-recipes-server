<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$count = 0;
function expectInvite(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
}
function rejectInvite(callable $action, int $status): void {
    try { $action(); } catch (HttpError $error) { expectInvite($error->status === $status, 'Wrong rejection: ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected invitation rejection.');
}
$config = configuration();
if ($config['environment'] !== 'development') throw new RuntimeException('Run only in development.');
$db = $config['database'];
$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$testDatabase = 'coffey_invite_test_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $config['database']['name'] = $testDatabase;
    $pdo = database($config);
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        foreach (explode(';', file_get_contents($file)) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
    }
    $households = new HouseholdService($pdo);
    $identity = static fn(string $uid, string $email, bool $verified = true): array => ['uid' => $uid, 'name' => ucfirst($uid), 'email' => $email, 'email_verified' => $verified];
    $owner = $households->bootstrap($identity('owner', 'owner@example.test'));
    $foreign = $households->bootstrap($identity('foreign', 'foreign@example.test'));
    $h = $households->create($owner['id'], 'Our table', 'Owner');
    $other = $households->create($foreign['id'], 'Other table', 'Foreign');
    $person = $households->addPerson($owner['id'], $h['id'], 'Guest');
    $existing = $households->bootstrap($identity('existing', 'existing@example.test'));
    $directory = ['owner@example.test' => ['uid' => 'owner'], 'existing@example.test' => ['uid' => 'existing']];
    $messages = [];
    $service = new InvitationService($pdo, $households,
        static function (string $email) use (&$directory): ?array { return $directory[$email] ?? null; },
        static function (string $email, string $text) use (&$messages): void { $messages[] = [$email, $text]; },
        'https://example.test/app/');
    rejectInvite(static fn() => $service->invite($foreign, $h['id'], $person['id'], 'new@example.test'), 404);
    rejectInvite(static fn() => $service->invite($owner, $h['id'], $person['id'], 'bad'), 422);
    rejectInvite(static fn() => $service->invite($owner, $h['id'], $person['id'], 'owner@example.test'), 409);
    expectInvite($messages === [], 'Rejected invites sent mail.');
    $invite = $service->invite($owner, $h['id'], $person['id'], ' NEW@example.test ');
    expectInvite($invite['status'] === 'pending', 'Invite not pending after email.');
    expectInvite($messages[0][0] === 'new@example.test' && str_contains($messages[0][1], 'Create an account'), 'New-account email or normalization wrong.');
    expectInvite(str_contains($messages[0][1], 'Owner has invited you') && str_contains($messages[0][1], 'Our table'), 'Inviter/household absent from email.');
    expectInvite($households->people($owner['id'], $h['id'])[1]['user_id'] === null, 'Sending invitation created membership.');
    rejectInvite(static fn() => $service->invite($owner, $h['id'], $person['id'], 'another@example.test'), 409);
    $unverified = $households->bootstrap($identity('new', 'new@example.test', false));
    $pending = $service->pending($unverified);
    expectInvite($pending['verification_required'] && $pending['invitations'] === [], 'Unverified account saw invitation details.');
    rejectInvite(static fn() => $service->respond($unverified, $invite['id'], 'accept'), 403);
    $new = $households->bootstrap($identity('new', 'new@example.test'));
    expectInvite(count($service->pending($new)['invitations']) === 1, 'New signup did not match invite.');
    rejectInvite(static fn() => $service->respond($existing, $invite['id'], 'accept'), 404);
    rejectInvite(static fn() => $service->respond($new, $invite['id'], 'invalid'), 422);
    $secondPerson = $households->addPerson($foreign['id'], $other['id'], 'Also guest');
    $second = $service->invite($foreign, $other['id'], $secondPerson['id'], 'new@example.test');
    expectInvite(count($service->pending($new)['invitations']) === 2, 'Multiple pending invitations lost.');
    $service->respond($new, $invite['id'], 'accept');
    expectInvite(count($households->households($new['id'])) === 1, 'Acceptance did not create one membership.');
    $people = $households->people($owner['id'], $h['id']);
    expectInvite(count($people) === 2 && $people[1]['id'] === $person['id'] && $people[1]['user_id'] === $new['id'], 'Acceptance did not link original person.');
    expectInvite($households->households($new['id'])[0]['role'] === 'member', 'Invitation granted administrator access.');
    expectInvite($pdo->query('SELECT status FROM household_invitations WHERE id = ' . (int) $second['id'])->fetchColumn() === 'cancelled', 'Acceptance left competing invitations active.');
    rejectInvite(static fn() => $service->respond($new, $invite['id'], 'accept'), 409);
    rejectInvite(static fn() => $service->invite($new, $h['id'], $secondPerson['id'], 'abc@example.test'), 403);
    rejectInvite(static fn() => $households->create($new['id'], 'Duplicate', 'New'), 409);
    $p2 = $households->addPerson($owner['id'], $h['id'], 'Existing guest');
    $i2 = $service->invite($owner, $h['id'], $p2['id'], 'existing@example.test');
    expectInvite(str_contains(end($messages)[1], 'Log in with this email address') && !str_contains(end($messages)[1], 'Create an account'), 'Existing-account email wrong.');
    $service->respond($existing, $i2['id'], 'decline');
    expectInvite($households->households($existing['id']) === [], 'Declining created membership.');
    expectInvite($households->people($owner['id'], $h['id'])[2]['user_id'] === null, 'Declining changed person.');
    $expired = $service->invite($owner, $h['id'], $p2['id'], 'existing@example.test');
    $pdo->exec('UPDATE household_invitations SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ' . (int) $expired['id']);
    expectInvite($service->pending($existing)['invitations'] === [], 'Expired invitation visible.');
    rejectInvite(static fn() => $service->respond($existing, $expired['id'], 'accept'), 409);
    $failedMail = new InvitationService($pdo, $households, static fn() => null, static function (): void { throw new RuntimeException('SMTP unavailable'); }, 'https://example.test/');
    rejectInvite(static fn() => $failedMail->invite($owner, $h['id'], $p2['id'], 'failure@example.test'), 503);
    expectInvite($pdo->query("SELECT status FROM household_invitations WHERE email = 'failure@example.test'")->fetchColumn() === 'failed', 'Failed mail became pending.');
    $retry = $service->invite($owner, $h['id'], $p2['id'], 'existing@example.test');
    $households->create($existing['id'], 'Own household', 'Existing');
    rejectInvite(static fn() => $service->respond($existing, $retry['id'], 'accept'), 409);
    expectInvite(count($households->households($existing['id'])) === 1, 'Acceptance bypassed single household policy.');
    expectInvite(!str_contains(InvitationMail::text('Owner', 'Our table', false, 'https://example.test'), 'password'), 'Invitation exposed credentials.');
} finally {
    if (!preg_match('/^coffey_invite_test_[a-f0-9]{10}$/', $testDatabase)) throw new RuntimeException('Invalid test database name.');
    $admin->exec("DROP DATABASE `$testDatabase`");
}
echo "$count invitation checks passed.\n";
