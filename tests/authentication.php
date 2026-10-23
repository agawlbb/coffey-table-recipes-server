<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Firebase\JWT\JWT;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
}
function rejected(callable $action, int $status): void {
    try { $action(); } catch (HttpError $error) { check($error->status === $status, 'Wrong rejection status.'); return; }
    throw new RuntimeException('Expected request rejection.');
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048,
    'config' => 'C:/xampp/apache/conf/openssl.cnf']);
if ($key === false) throw new RuntimeException('Cannot generate test signing key.');
openssl_pkey_export($key, $privateKey, null, ['config' => 'C:/xampp/apache/conf/openssl.cnf']);
$publicKey = openssl_pkey_get_details($key)['key'];
$verifier = new FirebaseIdentity('test-project', static fn() => ['test-key' => $publicKey]);
$claims = ['sub' => 'alice', 'aud' => 'test-project', 'iss' => 'https://securetoken.google.com/test-project',
    'iat' => time() - 10, 'exp' => time() + 1000, 'auth_time' => time() - 20,
    'name' => 'Alice', 'firebase' => ['sign_in_provider' => 'google.com']];
$encode = static fn(array $payload): string => JWT::encode($payload, $privateKey, 'RS256', 'test-key');
check($verifier->verify($encode($claims))['uid'] === 'alice', 'Valid signed identity rejected.');
check($verifier->verify($encode($claims))['auth_time'] === $claims['auth_time'], 'Verified login time not retained.');
check($verifier->verify($encode(array_replace($claims, ['firebase' => ['sign_in_provider' => 'password']])))['uid'] === 'alice', 'Email identity rejected.');
foreach ([['aud', 'another-project'], ['iss', 'https://attacker.example'], ['sub', ''],
    ['sub', str_repeat('x', 129)], ['exp', time() - 1], ['iat', time() + 100],
    ['auth_time', time() + 100], ['auth_time', -1], ['firebase', ['sign_in_provider' => 'anonymous']]] as [$claim, $value]) {
    rejected(static fn() => $verifier->verify($encode(array_replace($claims, [$claim => $value]))), 401);
}
foreach (['exp', 'iat', 'auth_time', 'sub'] as $claim) {
    $invalid = $claims; unset($invalid[$claim]);
    rejected(static fn() => $verifier->verify($encode($invalid)), 401);
}
$token = $encode($claims);
$parts = explode('.', $token);
$parts[1] = JWT::urlsafeB64Encode(json_encode(array_replace($claims, ['sub' => 'attacker'])));
rejected(static fn() => $verifier->verify(implode('.', $parts)), 401);
rejected(static fn() => $verifier->verify(JWT::encode($claims, str_repeat('x', 64), 'HS256', 'test-key')), 401);
rejected(static fn() => $verifier->verify(JWT::encode($claims, $privateKey, 'RS256', 'unknown-key')), 401);
rejected(static fn() => $verifier->verify('invalid'), 401);
rejected(static fn() => (new FirebaseIdentity('', static fn() => []))->verify($token), 503);

if (in_array('--identity-only', $argv, true)) {echo "$count signed identity checks passed.\n"; exit;}

// Disposable database created solely for this test. Never modify household data.
$config = configuration();
if ($config['environment'] !== 'development') throw new RuntimeException('Run only in development.');
$db = $config['database'];
$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$testDatabase = 'coffey_auth_test_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $config['database']['name'] = $testDatabase;
    $pdo = database($config);
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        foreach (explode(';', file_get_contents($file)) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
    }
    $service = new HouseholdService($pdo);
    $alice = $service->bootstrap(['uid' => 'alice', 'name' => 'Alice']);
    $bob = $service->bootstrap(['uid' => 'bob', 'name' => 'Bob']);
    check($service->bootstrap(['uid' => 'alice', 'name' => 'Alice'])['id'] === $alice['id'], 'Bootstrap duplicates users.');
    $a = $service->create($alice['id'], 'Household A', 'Alice');
    $b = $service->create($bob['id'], 'Household B', 'Bob');
    check(count($service->households($alice['id'])) === 1, 'Foreign households disclosed.');
    check(count($service->people($alice['id'], $a['id'])) === 1, 'Owner person missing.');
    rejected(static fn() => $service->people($alice['id'], $b['id']), 404);
    rejected(static fn() => $service->addPerson($alice['id'], $b['id'], 'Intruder'), 404);
    rejected(static fn() => $service->create($alice['id'], 'Second household', 'Alice'), 409);
    check(count($service->households($alice['id'])) === 1, 'Rejected creation left another household.');
    check($service->rename($alice['id'], $a['id'], 'Renamed A')['name'] === 'Renamed A', 'Owner rename failed.');
    check($service->households($alice['id'])[0]['name'] === 'Renamed A', 'Rename was not persisted.');
    rejected(static fn() => $service->rename($alice['id'], $b['id'], 'Intruder'), 404);
    rejected(static fn() => $service->rename($alice['id'], $a['id'], '   '), 400);
    $person = $service->addPerson($alice['id'], $a['id'], 'A child');
    check($person['user_id'] === null, 'Household people incorrectly require a login.');
    $statement = $pdo->prepare("INSERT INTO household_memberships (household_id, user_id, role) VALUES (?, ?, 'member')");
    $statement->execute([$a['id'], $bob['id']]);
    check(count($service->households($bob['id'])) === 2, 'Multiple household membership failed.');
    check(count($service->people($bob['id'], $a['id'])) === 2, 'Member cannot read their own household.');
    rejected(static fn() => $service->addPerson($bob['id'], $a['id'], 'Not allowed'), 403);
    rejected(static fn() => $service->rename($bob['id'], $a['id'], 'Not allowed'), 403);
    rejected(static fn() => $service->renameOwnPerson($alice['id'], $b['id'], 'Intruder'), 404);
    rejected(static fn() => $service->renameOwnPerson($bob['id'], $a['id'], 'Missing person'), 404);
    rejected(static fn() => $service->renameOwnPerson($alice['id'], $a['id'], '  '), 400);
    rejected(static fn() => $service->renameOwnPerson($alice['id'], $a['id'], str_repeat('x', 201)), 400);
    $ownerPerson = $service->people($alice['id'], $a['id'])[0];
    $renamed = $service->renameOwnPerson($alice['id'], $a['id'], '  Alice at the table  ');
    check($renamed['id'] === $ownerPerson['id'], 'Rename replaced the original person.');
    check($renamed['display_name'] === 'Alice at the table', 'Rename did not trim the name.');
    check($service->people($alice['id'], $a['id'])[0]['display_name'] === 'Alice at the table', 'Table name was not persisted.');
    check($service->bootstrap(['uid' => 'alice', 'name' => 'Account Alice'])['display_name'] === 'Account Alice', 'Account name changed unexpectedly.');
    check($service->people($alice['id'], $a['id'])[0]['display_name'] === 'Alice at the table', 'Account bootstrap overwrote table name.');
    check($service->people($alice['id'], $a['id'])[1]['display_name'] === 'A child', 'Rename changed another person.');
    $statement = $pdo->prepare('INSERT INTO household_people (household_id, user_id, display_name) VALUES (?, ?, ?)');
    $statement->execute([$a['id'], $bob['id'], 'Member Bob']);
    check($service->renameOwnPerson($bob['id'], $a['id'], 'Bobby')['display_name'] === 'Bobby', 'Ordinary member cannot rename their own person.');
    check($service->people($bob['id'], $b['id'])[0]['display_name'] === 'Bob', 'Rename leaked to another household.');
    try {
        $statement = $pdo->prepare('INSERT INTO household_people (household_id, user_id, display_name) VALUES (?, ?, ?)');
        $statement->execute([$b['id'], $alice['id'], 'Wrong household']);
        throw new RuntimeException('Cross-household person link accepted.');
    } catch (PDOException $error) { check($error->getCode() === '23000', 'Unexpected database error.'); }
    $pdo->exec('UPDATE users SET disabled = 1, display_name = NULL WHERE id = ' . (int) $alice['id']);
    rejected(static fn() => $service->bootstrap(['uid' => 'alice', 'name' => 'Alice']), 403);
    check($pdo->query('SELECT display_name FROM users WHERE id = ' . (int) $alice['id'])->fetchColumn() === null, 'Disabled account profile was restored.');
} finally {
    if (!preg_match('/^coffey_auth_test_[a-f0-9]{10}$/', $testDatabase)) throw new RuntimeException('Invalid test database name.');
    $admin->exec("DROP DATABASE `$testDatabase`");
}
echo "$count authentication and household checks passed.\n";
