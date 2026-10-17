<?php
declare(strict_types=1);
// Operator-only, one-shot test. Never expose this entrypoint over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
if ($argc !== 3 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{32}$/D', $argv[2])) {
    fwrite(STDERR, "Usage: php send-test-timer.php EMAIL UNIQUE_RUN_HEX\n"); exit(1);
}
umask(0077);
$receipt = dirname(__DIR__).'/storage/test-timer-'.$argv[2].'.json';
$handle = fopen($receipt, 'x');
if ($handle === false) exit(1); // Repeated cron execution cannot send twice.
$pdo = null; $source = null; $user = null;
try {
    $config = configuration();
    $account = (new FirebaseAccountDirectory($config))->lookup($argv[1]);
    if (!$account || $account['disabled'] || !$account['email_verified']) throw new RuntimeException('Account unavailable');
    $pdo = database($config);
    $q = $pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');
    $q->execute([$account['uid']]); $user = $q->fetchColumn();
    if (!$user) throw new RuntimeException('App account unavailable');
    $q = $pdo->prepare("SELECT installation_id FROM timer_devices WHERE user_id=? AND platform='android' AND receive_timers=1 AND push_token IS NOT NULL AND updated_at>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
    $q->execute([$user]); $devices = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($devices) !== 1) throw new RuntimeException('Expected exactly one opted-in Android device');
    $source = bin2hex(random_bytes(16));
    $push = new TimerPush($config); $accepted = 0;
    $service = new TimerSyncService($pdo, function(array $device, array $timer) use ($push, &$accepted): bool {
        $valid = $push->send($device, $timer); if ($valid) ++$accepted; return $valid;
    });
    $service->device((string)$user, ['installation_id'=>$source, 'platform'=>'android', 'receive_timers'=>false]);
    $deadline = (int)floor(microtime(true)*1000)+120000;
    $result = $service->change((string)$user, $account['uid'], [
        'installation_id'=>$source, 'timer_id'=>$source.':test:timer', 'operation_id'=>$argv[2],
        'revision'=>0, 'cancelled'=>false, 'title'=>'Two-minute sync test', 'deadline_ms'=>$deadline,
    ]);
    $report = ['status'=>'completed', 'android_registrations'=>count($devices),
        'fcm_accepted'=>$accepted, 'delivery'=>$result['delivery'], 'deadline_utc'=>gmdate('c', (int)($deadline/1000)),
        'note'=>'FCM acceptance does not confirm device scheduling or audibility.'];
} catch (Throwable $error) {
    $report = ['status'=>'failed', 'reason'=>$error instanceof RuntimeException ? $error->getMessage() : 'Test failed'];
} finally {
    if ($pdo && $source && $user) $pdo->prepare('DELETE FROM timer_devices WHERE user_id=? AND installation_id=?')->execute([$user,$source]);
}
fwrite($handle, json_encode($report, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)); fclose($handle);
echo json_encode($report, JSON_THROW_ON_ERROR)."\n";
