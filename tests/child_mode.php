<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$directory=sys_get_temp_dir().'/ctr-child-'.bin2hex(random_bytes(8));
$mode=new ChildMode($directory);
$identity=['uid'=>'child','auth_time'=>time()-100];
function childCheck(bool $ok):void {if(!$ok) throw new RuntimeException('Child mode check failed');}
function childReject(callable $action,int $status):void {try{$action();}catch(HttpError $e){childCheck($e->status===$status);return;}throw new RuntimeException('Expected policy rejection');}
try {
    childCheck($mode->state($identity)===['enabled'=>false]);
    childReject(fn()=>$mode->change($identity,['enabled'=>true,'pin'=>'abc']),422);
    childCheck($mode->change($identity,['enabled'=>true,'pin'=>'1234'])['enabled']);
    childCheck(!(new ChildMode($directory))->state(['uid'=>'other'])['enabled']);
    foreach(['/households/1/meal-plan','/households/1/shopping-list','/households/1/recipes','/admin/users/1/delete','/me/timers'] as $route) childReject(fn()=>$mode->guard($identity,$route,'POST'),403);
    $mode->guard($identity,'/households/1/recipes/2/favorites','POST');
    $mode->guard($identity,'/households/1/meal-plan','GET');
    childReject(fn()=>$mode->change($identity,['enabled'=>true,'pin'=>'5678']),403);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'5678']),403);
    childCheck($mode->state($identity)['enabled']);
    $mode->requestReset('child');
    childCheck($mode->state($identity)['enabled']); // Refreshing a token is not a new login.
    childCheck(!$mode->state(['uid'=>'child','auth_time'=>time()+1])['enabled']);
    childCheck($mode->change($identity,['enabled'=>true,'pin'=>'5678'])['enabled']);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'1234']),403); // Old PIN cleared.
    childCheck(!$mode->change($identity,['enabled'=>false,'pin'=>'5678'])['enabled']);
    $mode->change($identity,['enabled'=>true,'pin'=>'1234']);
    for($i=0;$i<5;$i++) childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'0000']),403);
    childReject(fn()=>$mode->change($identity,['enabled'=>false,'pin'=>'1234']),429);
    $stored=file_get_contents($directory.'/'.hash('sha256','child').'.json');
    childCheck(strpos($stored,'"pin":')===false && strpos($stored,'1234')===false);
    echo "Child mode default, isolation, PIN, throttle, write policy and next-login recovery passed.\n";
} finally {foreach(glob($directory.'/*')?:[] as $file)unlink($file);if(is_dir($directory))rmdir($directory);}
