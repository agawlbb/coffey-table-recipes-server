<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/MigrationChecksums.php';
require dirname(__DIR__).'/src/SharedRecipeRollout.php';
$port=(int)($argv[1]??0);$expected=realpath($argv[2]??'');
if($port<1024||$port===3306||!$expected||!is_file($expected.'/.coffey-disposable'))throw new RuntimeException('Explicit marked disposable instance required.');
function connectRollout(?string $name=null):PDO{
    global $port,$expected;
    if($name!==null&&!preg_match('/^coffey_rollout_[a-f0-9]{16}$/D',$name))throw new RuntimeException('Unsafe schema name.');
    $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.($name?';dbname='.$name:'').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if(strcasecmp((string)realpath((string)$pdo->query('SELECT @@datadir')->fetchColumn()),$expected)!==0)throw new RuntimeException('Wrong server directory.');
    $pdo->exec("SET time_zone='+00:00'");return $pdo;
}
$admin=connectRollout();$created=[];$checks=0;$directories=[];
function expectRollout(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejectRollout(callable $action,string $part):void{try{$action();}catch(RuntimeException $e){expectRollout(str_contains($e->getMessage(),$part),'Unexpected failure: '.$e->getMessage());return;}throw new RuntimeException('Expected failure: '.$part);}
function freshSchema():PDO{global $admin,$created;$name='coffey_rollout_'.bin2hex(random_bytes(8));$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created[]=$name;return connectRollout($name);}
try{
 foreach(['success','checksum','changed','unverified','partial011','partial012'] as $case){
    $pdo=freshSchema();$recovery=freshSchema();
    $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(120) PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB');
    $migrations=dirname(__DIR__).'/database/migrations';
    foreach(glob($migrations.'/*.sql') as $file){if((int)basename($file)>10)continue;foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')$pdo->exec($sql);$pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)')->execute([basename($file),hash_file('sha256',$file)]);}
    $h=new HouseholdService($pdo);$u=$h->bootstrap(['uid'=>'synthetic-rollout','name'=>'Test']);$hh=$h->create($u['id'],'Synthetic','Test');
    $pdo->prepare('INSERT INTO recipes(household_id,title,notes) VALUES (?,?,?)')->execute([$hh['id'],'Semicolon; quoted soup',"Line one;\nLine two 'quote' \\ backslash"]);
    $id=$pdo->lastInsertId();$pdo->prepare('INSERT INTO recipes(household_id,title,parent_recipe_id,deleted_at,purge_after) VALUES (?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY))')->execute([$hh['id'],'Recoverable trash',$id]);
    $dir=sys_get_temp_dir().'/coffey-rollout-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);$directories[]=$dir;
    $service=new SharedRecipeRollout($pdo,$dir,$migrations);$backup=$service->backup();
    expectRollout($backup['inventory']['recipes']['rows']===2,'Backup recipe inventory mismatch.');
    rejectRollout(fn()=>$service->restoreDrill($pdo,$backup),'must differ');
    $service->restoreDrill($recovery,$backup);$backup['restore_verified']=true;
    expectRollout((new SharedRecipeRollout($recovery,$dir,$migrations))->inventory()===$backup['inventory'],'Restore lost data.');
    rejectRollout(fn()=>$service->restoreDrill($recovery,$backup),'must be empty');
    if($case==='checksum'){
        file_put_contents($dir.'/database.jsonl',"\n",FILE_APPEND);rejectRollout(fn()=>$service->apply($backup),'checksum');
        expectRollout(!$pdo->query("SHOW TABLES LIKE 'recipe_households'")->fetch(),'Corrupt backup allowed DDL.');
    }elseif($case==='changed'){
        $pdo->exec("UPDATE recipes SET title='Changed after snapshot' WHERE id=".(int)$id);rejectRollout(fn()=>$service->apply($backup),'changed after backup');
    }elseif($case==='unverified'){
        unset($backup['restore_verified']);rejectRollout(fn()=>$service->apply($backup),'restore drill');
    }elseif(str_starts_with($case,'partial')){
        $target=$case==='partial011'?'011_shared_recipes.sql':'012_canonical_relationships.sql';
        rejectRollout(fn()=>$service->apply($backup,static function($name,$index)use($target){if($name===$target && $index===0)throw new RuntimeException('Injected interruption after DDL');}),'Injected interruption');
        expectRollout(is_file($dir.'/ddl-started.json'),'Partial DDL journal missing.');
        // DDL changed inventory, so either guard is acceptable, but no replay is allowed.
        try{$service->apply($backup);throw new LogicException('Partial migration replayed.');}catch(RuntimeException $e){expectRollout(str_contains($e->getMessage(),'after backup')||str_contains($e->getMessage(),'Prior DDL'),'Partial DDL did not fail closed.');}
        expectRollout(hash_file('sha256',$dir.'/database.jsonl')===$backup['backup_sha256'],'Failure changed recovery backup.');
    }else{
        $service->apply($backup);$service->verify($backup);
        expectRollout((int)$pdo->query('SELECT COUNT(*) FROM recipe_households')->fetchColumn()===2,'Backfill failed.');
        expectRollout((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('011_shared_recipes.sql','012_canonical_relationships.sql')")->fetchColumn()===2,'Migration order/ledger failed.');
        rejectRollout(fn()=>$service->apply($backup),'after backup');
    }
 }
 echo "$checks isolated rollout backup/restore/failure checks passed.\n";
}finally{
 $pdo=null;$recovery=null;$service=null;
 foreach($created as $name){if(!preg_match('/^coffey_rollout_[a-f0-9]{16}$/D',$name))throw new RuntimeException('Unsafe cleanup');$admin->exec("DROP DATABASE `$name`");}
 foreach($directories as $dir){foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
 echo "Removed only synthetic schemas and this test's temporary backups.\n";
}
