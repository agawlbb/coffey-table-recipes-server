<?php
declare(strict_types=1);
require __DIR__.'/household_snapshot.php';
$directory=sys_get_temp_dir().'/ctr-people-child-'.bin2hex(random_bytes(8));
$modes=new ChildMode($directory);
try {
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT);
        INSERT INTO users VALUES(1,'parent'),(2,'child'),(3,'outsider');
        INSERT INTO household_people VALUES(11,1,2,'Child'),(12,1,NULL,'Invited'),(13,2,3,'Other table');");
    $modes->change(['uid'=>'child'],['enabled'=>true,'pin'=>'1234']);
    $households=new HouseholdService($pdo,null,$modes);
    $people=$households->people('1','1');
    verifySnapshot(array_column($people,'child_mode')===[false,true,false]);
    verifySnapshot(!str_contains(json_encode($people),'firebase_uid'));
    verifySnapshot(!str_contains(json_encode($people),'pin'));
    verifySnapshot(count($people)===3);
    try { $households->people('3','1'); throw new RuntimeException('Nonmember read allowed'); }
    catch(HttpError $e) { verifySnapshot($e->status===404); }
    $snapshot=new HouseholdSnapshot($pdo,$households,'https://example.test/media');
    $before=$snapshot->read('1','1');
    verifySnapshot($before['responses']['/households/1/people']['people'][1]['child_mode']===true);
    $modes->requestReset('child');
    verifySnapshot($households->people('1','1')[1]['child_mode']===true);
    $modes->state(['uid'=>'child','auth_time'=>time()+1]);
    verifySnapshot($households->people('1','1')[1]['child_mode']===false);
    verifySnapshot($snapshot->read('1','1')['version']!==$before['version']);
    echo "Household child status: scoped boolean, privacy, pending reset and snapshot invalidation passed.\n";
} finally {
    foreach(glob($directory.'/*')?:[] as $file) unlink($file);
    if(is_dir($directory))rmdir($directory);
}
