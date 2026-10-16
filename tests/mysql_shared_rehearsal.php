<?php
declare(strict_types=1);
// Explicit opt-in only. Never loads application configuration or credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$port=(int)($argv[1] ?? 0); $expected=realpath($argv[2] ?? '');
if ($port<1024 || $port===3306 || !$expected || !is_file($expected.'/.coffey-disposable')) throw new RuntimeException('Provide a nonstandard loopback port and marked, freshly initialized disposable data directory.');
function connectTest(?string $name=null): PDO {
    global $port,$expected;
    if ($name!==null && !preg_match('/^coffey_rehearsal_[a-f0-9]{16}$/D',$name)) throw new RuntimeException('Unsafe test database name.');
    $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.($name===null ? '' : ';dbname='.$name).';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $actual=realpath((string)$pdo->query('SELECT @@datadir')->fetchColumn());
    if ($actual===false || strcasecmp($actual,$expected)!==0) throw new RuntimeException('Server data directory does not match the disposable directory.');
    $pdo->exec("SET time_zone='+00:00'"); $pdo->exec('SET innodb_lock_wait_timeout=10');
    return $pdo;
}
function action(PDO $pdo,array $job): array {
    $h=new HouseholdService($pdo); $read=new RecipeService($pdo,$h); $trash=new RecipeDeletionService($pdo,$h);
    try {
        $value=match($job['action']) {
            'attach'=>(new RecipeMembership($pdo,$h))->attach($job['user'],$job['household'],$job['id']),
            'delete'=>$trash->delete($job['user'],$job['household'],$job['id'],['revision'=>$job['revision']]),
            'restore'=>$trash->restore($job['user'],$job['household'],$job['id']),
            'save'=>(new RecipeEditorService($pdo,$h,sys_get_temp_dir(),'https://example.invalid'))->save($job['user'],$job['household'],$job['id'],$job['draft']),
            'purge'=>$trash->purgeExpired(sys_get_temp_dir()),
        };
        return ['status'=>200,'value'=>$value];
    } catch (HttpError $e) { return ['status'=>$e->status,'code'=>$e->errorCode]; }
}
if (isset($argv[3])) {
    $job=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
    try { $pdo=connectTest($job['database']); file_put_contents($argv[3].'.ready','ready'); echo json_encode(action($pdo,$job),JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { fwrite(STDERR,(string)$e); exit(1); }
    exit;
}
$checks=0;
function check(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function migrate(PDO $pdo,string $file): void {
    foreach(explode(';',file_get_contents($file)) as $sql) if(trim($sql)!=='') $pdo->exec($sql);
}
function race(PDO $pdo,string $id,array $jobs): array {
    global $name,$port,$expected;
    $processes=[]; $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT id FROM recipes WHERE id=? FOR UPDATE'); $lock->execute([$id]);
    try {
        foreach($jobs as $job) {
            $file=tempnam(sys_get_temp_dir(),'coffey-race-');
            file_put_contents($file,json_encode($job+['database'=>$name],JSON_THROW_ON_ERROR));
            $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,(string)$port,$expected,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process)) throw new RuntimeException('Could not launch race worker.');
            fclose($pipes[0]); $processes[]=[$process,$pipes,$file];
        }
        $deadline=microtime(true)+8;
        foreach($processes as [$_,$pipes,$file]) {
            while(!is_file($file.'.ready')) { if(microtime(true)>$deadline) throw new RuntimeException('Worker did not become ready.'); usleep(10000); }
        }
        // Both independent sessions have connected before releasing the shared lock.
        usleep(100000); $pdo->commit();
        $results=[];
        foreach($processes as [$process,$pipes,$file]) {
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
            if($code!==0) throw new RuntimeException('Race worker failed: '.$err);
            $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        if($pdo->inTransaction()) $pdo->rollBack();
        foreach($processes as [$process,$pipes,$file]) {
            if(is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
            if(is_file($file)) unlink($file); if(is_file($file.'.ready')) unlink($file.'.ready');
        }
    }
}
$admin=connectTest();$name='coffey_rehearsal_'.bin2hex(random_bytes(8));$created=false;
try {
    echo 'Server: '.$admin->query('SELECT VERSION()')->fetchColumn()."\n";
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;$pdo=connectTest($name);
    foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) if((int)substr(basename($file),0,3)<11) migrate($pdo,$file);
    $h=new HouseholdService($pdo);
    $a=$h->bootstrap(['uid'=>'synthetic-a','name'=>'Test A'])['id'];$b=$h->bootstrap(['uid'=>'synthetic-b','name'=>'Test B'])['id'];
    $ha=$h->create($a,'Synthetic A','Test A')['id'];$hb=$h->create($b,'Synthetic B','Test B')['id'];
    $pdo->prepare('INSERT INTO recipes(household_id,title,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?)')->execute([$ha,'Pre-migration live',$a,$a]);$legacy=$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recipes(household_id,title,deleted_at,purge_after) VALUES (?, 'Pre-migration trash', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY))")->execute([$ha]);$oldTrash=$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recipe_steps(household_id,recipe_id,position,instruction) VALUES (?,?,1,'Preserve original child')")->execute([$ha,$legacy]);$step=$pdo->lastInsertId();
    $pdo->prepare('UPDATE recipes SET parent_recipe_id=? WHERE id=?')->execute([$legacy,$oldTrash]);
    $pdo->prepare("INSERT INTO recipe_pairings(household_id,recipe_id,paired_recipe_id,notes) VALUES (?,?,?,'Preserve existing pairing')")->execute([$ha,$legacy,$oldTrash]);
    migrate($pdo,dirname(__DIR__).'/database/migrations/011_shared_recipes.sql');
    migrate($pdo,dirname(__DIR__).'/database/migrations/012_canonical_relationships.sql');
    check((int)$pdo->query('SELECT COUNT(*) FROM recipe_households')->fetchColumn()===2,'Migration did not backfill both live and deleted recipes.');
    check($pdo->query('SELECT instruction FROM recipe_steps WHERE id='.(int)$step)->fetchColumn()==='Preserve original child','Migration changed child content.');
    check((string)$pdo->query('SELECT trash_household_id FROM recipes WHERE id='.(int)$oldTrash)->fetchColumn()===$ha,'Migration lost trash ownership.');
    check((string)$pdo->query('SELECT parent_recipe_id FROM recipes WHERE id='.(int)$oldTrash)->fetchColumn()===$legacy,'Migration changed an existing parent link.');
    check($pdo->query('SELECT notes FROM recipe_pairings WHERE recipe_id='.(int)$legacy)->fetchColumn()==='Preserve existing pairing','Migration lost an existing pairing.');
    $read=new RecipeService($pdo,$h);$links=new RecipeMembership($pdo,$h);$trash=new RecipeDeletionService($pdo,$h);$edit=new RecipeEditorService($pdo,$h,sys_get_temp_dir(),'https://example.invalid');
    $base=['title'=>'Synthetic soup','original_language'=>'en','measurement_system'=>'metric','ingredients'=>[],'groups'=>[],'steps'=>[],'actions'=>[],'photos'=>[],'tags'=>[],'category_assignments'=>[],'pairings'=>[]];
    $recipe=$edit->save($a,$ha,'',$base);$id=$recipe['id'];$links->attach($b,$hb,$id);
    $draft=$read->detail($b,$hb,$id);$draft['title']='Edited in B';$draft['confirm_shared_edit']=true;$updated=$edit->save($b,$hb,$id,$draft);
    check($read->detail($a,$ha,$id)['title']==='Edited in B','Cross-household edit failed.');
    check((string)$pdo->query('SELECT updated_by_user_id FROM recipes WHERE id='.(int)$id)->fetchColumn()===$b,'Foreign-household editor FK failed.');
    check($read->detail('','0',$id,true)['id']===$id,'Public detail failed.');
    require __DIR__.'/canonical_relationship_cases.php';
    canonicalRelationshipCases($pdo,$h,$a,$ha,$b,$hb,'check');
    for($iteration=0;$iteration<10;$iteration++) {
        $r=$edit->save($a,$ha,'',$base);$id=$r['id'];$links->attach($b,$hb,$id);
        $draft=$read->detail($a,$ha,$id);$draft['confirm_shared_edit']=true;
        $jobs=[];foreach([[$a,$ha],[$b,$hb]] as [$user,$household]) $jobs[]=['action'=>'save','user'=>$user,'household'=>$household,'id'=>$id,'draft'=>$draft];
        $results=race($pdo,$id,$jobs);$statuses=array_column($results,'status');sort($statuses);check($statuses===[200,409],'Concurrent edits did not serialize revisions.');
        $rev=$read->detail($a,$ha,$id)['revision'];$jobs=[];
        foreach([[$a,$ha],[$b,$hb]] as [$user,$household]) $jobs[]=['action'=>'delete','user'=>$user,'household'=>$household,'id'=>$id,'revision'=>$rev];
        $results=race($pdo,$id,$jobs);check(array_column($results,'status')===[200,200],'Concurrent household removals failed.');
        check((int)$pdo->query('SELECT COUNT(*) FROM recipe_households WHERE removed_at IS NULL AND recipe_id='.(int)$id)->fetchColumn()===0,'Final removal left active attachment.');
        $row=$pdo->query('SELECT * FROM recipes WHERE id='.(int)$id)->fetch(PDO::FETCH_ASSOC);check($row['deleted_at']!==null && $row['purge_after']!==null,'Final concurrent removal lost recovery window.');
        $last=(string)$row['trash_household_id'];$user=$last===$ha?$a:$b;
        $results=race($pdo,$id,[['action'=>'restore','user'=>$user,'household'=>$last,'id'=>$id],['action'=>'attach','user'=>$a,'household'=>$ha,'id'=>$id]]);
        check($results[0]['status']===200 && in_array($results[1]['status'],[200,404],true),'Restore/attach race had unexpected outcome.');
        check($pdo->query('SELECT deleted_at FROM recipes WHERE id='.(int)$id)->fetchColumn()===null,'Restore/attach lost restored content.');
        // Two different storage households try to form a cycle concurrently.
        $left=$edit->save($a,$ha,'',$base); $right=$edit->save($b,$hb,'',$base);
        $left['parent_recipe_id']=$right['id']; $right['parent_recipe_id']=$left['id'];
        $results=race($pdo,$left['id'],[['action'=>'save','user'=>$a,'household'=>$ha,'id'=>$left['id'],'draft'=>$left],['action'=>'save','user'=>$b,'household'=>$hb,'id'=>$right['id'],'draft'=>$right]]);
        $statuses=array_column($results,'status');sort($statuses);check($statuses===[200,422],'Concurrent cross-household edits formed a cycle.');
        // Independent last-removal versus attach race.
        $r=$edit->save($a,$ha,'',$base);$id=$r['id'];
        $results=race($pdo,$id,[['action'=>'delete','user'=>$a,'household'=>$ha,'id'=>$id,'revision'=>$r['revision']],['action'=>'attach','user'=>$b,'household'=>$hb,'id'=>$id]]);
        check($results[0]['status']===200 && in_array($results[1]['status'],[200,404],true),'Delete/attach race failed.');
        $row=$pdo->query('SELECT deleted_at FROM recipes WHERE id='.(int)$id)->fetch(PDO::FETCH_ASSOC);
        $active=(int)$pdo->query('SELECT COUNT(*) FROM recipe_households WHERE removed_at IS NULL AND recipe_id='.(int)$id)->fetchColumn();
        check(($active>0)==($row['deleted_at']===null),'Delete/attach made a live recipe inaccessible or deleted an attached recipe.');
        if($active>0) $trash->delete($b,$hb,$id,['revision'=>$r['revision']]);
        $pdo->exec('UPDATE recipes SET purge_after=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY) WHERE id='.(int)$id);
        $last=(string)$pdo->query('SELECT trash_household_id FROM recipes WHERE id='.(int)$id)->fetchColumn();$user=$last===$ha?$a:$b;
        $results=race($pdo,$id,[['action'=>'restore','user'=>$user,'household'=>$last,'id'=>$id],['action'=>'purge']]);
        check(in_array($results[0]['status'],[404,410],true) && $results[1]['status']===200,'Expired restore/purge race failed.');
        check((int)$pdo->query('SELECT COUNT(*) FROM recipes WHERE id='.(int)$id)->fetchColumn()===0,'Purge did not remove expired canonical recipe.');
    }
    echo "$checks MariaDB migration/concurrency checks passed (10 iterations of six two-process races).\n";
} finally {
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $pdo=null;
    if($created) { $admin->exec("DROP DATABASE `$name`"); echo "Dropped only the generated synthetic test database.\n"; }
}
