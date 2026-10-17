<?php
declare(strict_types=1);
// Manual CLI only. No environment credentials are printed or saved by this helper.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/MigrationChecksums.php';
require dirname(__DIR__).'/src/SharedRecipeRollout.php';
$phase=$argv[1]??'';$account=$argv[2]??'';$argument=$argv[3]??'';$root=dirname(__DIR__);
if(!in_array($phase,['pause','backup','apply','verify','resume'],true) || !preg_match('/^[a-z0-9_]+$/D',$account) || $root!=='/home/'.$account.'/coffeybean.com/CoffeyTableRecipes') {fwrite(STDERR,"Invalid phase or deployment target.\n");exit(1);}
$dir=$root.'/storage/shared-rollout';$marker=$root.'/storage/shared-rollout-maintenance';
function recordState(string $dir,array $state):void{
    $temporary=$dir.'/state.tmp';$json=json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
    if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Cannot save deployment state.');chmod($temporary,0600);
    if(!rename($temporary,$dir.'/state.json'))throw new RuntimeException('Cannot publish deployment state.');
}
function webHashes(string $root):array{$hashes=[];foreach(['index.html','flutter_bootstrap.js','main.dart.js','version.json'] as $name){$path=$root.'/app/'.$name;if(!is_file($path))throw new RuntimeException('Web baseline file missing.');$hashes[$name]=hash_file('sha256',$path);}return $hashes;}
function mediaHashes(string $root):array {
    $hashes=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/media',FilesystemIterator::SKIP_DOTS)) as $file){
        if($file->isLink())throw new RuntimeException('Media symlink requires manual review.');
        if($file->isFile())$hashes[str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))]=hash_file('sha256',$file->getPathname());
    }
    ksort($hashes);return $hashes;
}
function pausedPurge(string $root):void{
    if(!function_exists('exec'))throw new RuntimeException('Cannot inspect cron/processes; use an enabled CLI runtime.');
    exec('crontab -l 2>/dev/null',$lines,$code);if($code!==0)throw new RuntimeException('Cron list unavailable; cannot verify pause.');
    foreach($lines as $line)if(!str_starts_with(ltrim($line),'#') && str_contains($line,$root.'/bin/purge-deleted-recipes.php'))throw new RuntimeException('Pause the exact recipe purge cron entry first; preserve its schedule.');
    exec('ps -eo args',$processes,$code);if($code!==0)throw new RuntimeException('Cannot verify background purge exit.');
    foreach($processes as $line)if(str_contains($line,$root.'/bin/purge-deleted-recipes.php'))throw new RuntimeException('A purge process is still running.');
}
function verifyRuntime(string $root,string $commit):void{
    if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Provide the exact deployed commit.');
    $receipt=json_decode(file_get_contents($root.'/backend-version.json'),true,512,JSON_THROW_ON_ERROR);
    if(($receipt['commit']??'')!==$commit)throw new RuntimeException('Deployment receipt does not match requested commit.');
    $zip=new ZipArchive();if($zip->open($root.'/storage/backend-'.$commit.'.zip')!==true)throw new RuntimeException('Deployment package unavailable.');
    try{
        foreach(['.htaccess','index.php','src/RecipeService.php','src/RecipeEditorService.php','src/RecipeMembership.php','src/RecipeDeletionService.php','src/RecipeShare.php','src/CategoryImageService.php','bin/purge-deleted-recipes.php'] as $name){
            $bytes=$zip->getFromName($name);if($bytes===false || !hash_equals(hash('sha256',$bytes),hash_file('sha256',$root.'/'.$name)))throw new RuntimeException('Deployed runtime/package mismatch.');
        }
        if(!str_contains(file_get_contents($root.'/.htaccess'),'shared-rollout-maintenance') || !str_contains(file_get_contents($root.'/bin/purge-deleted-recipes.php'),'shared-rollout-maintenance'))throw new RuntimeException('Published maintenance guards missing.');
    }finally{$zip->close();}
}
try{
    $config=configuration();if($config['database']['name']!==$account.'_recipes')throw new RuntimeException('Wrong configured database.');
    // Schema privileges must be supplied through a temporary process environment,
    // or the existing credential must already have them. Never change saved config.
    if(getenv('ROLLOUT_DB_USER')!==false){$config['database']['user']=getenv('ROLLOUT_DB_USER');$config['database']['password']=getenv('ROLLOUT_DB_PASSWORD')?:'';}
    $pdo=database($config);
    foreach(['coffey_table_recipes_migrations','coffey_recipe_trash_cleanup'] as $lock){$q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration/purge lock unavailable.');}
    pausedPurge($root);
    if($phase==='pause'){
        if(is_dir($dir)||file_exists($marker))throw new RuntimeException('Existing rollout state: inspect before continuing.');
        if(!class_exists('ZipArchive'))throw new RuntimeException('ZipArchive is required.');
        // Inspect compatibility before installing the maintenance gate.
        (new SharedRecipeRollout($pdo,$dir,$root.'/database/migrations'))->inspect();
        if(!mkdir($dir,0700))throw new RuntimeException('Cannot create protected rollout directory.');
        $archive=new ZipArchive();if($archive->open($dir.'/backend-media.zip',ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create backend backup.');
        $manifest=[];
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            if(preg_match('#^(app|storage|\.git)(/|$)#',$relative))continue;
            if($file->isLink())throw new RuntimeException('Backup symlink requires manual review.');if(!$file->isFile())continue;
            $manifest[$relative]=hash_file('sha256',$file->getPathname());if(!$archive->addFile($file->getPathname(),$relative))throw new RuntimeException('Cannot add backup file.');
        }
        if(!$archive->close())throw new RuntimeException('Cannot finish backend backup.');chmod($dir.'/backend-media.zip',0600);
        if($archive->open($dir.'/backend-media.zip')!==true)throw new RuntimeException('Cannot reopen backup.');
        foreach($manifest as $name=>$hash){$bytes=$archive->getFromName($name);if($bytes===false||hash('sha256',$bytes)!==$hash)throw new RuntimeException('Backend backup verification failed.');}$archive->close();
        $state=['phase'=>'paused','paused_at'=>time(),'web'=>webHashes($root),'backend_backup_sha256'=>hash_file('sha256',$dir.'/backend-media.zip'),'backend_files'=>$manifest];
        $state['media']=array_filter($manifest,static fn($name)=>str_starts_with($name,'media/'),ARRAY_FILTER_USE_KEY);ksort($state['media']);
        recordState($dir,$state);
        // The guard is also shipped in the new .htaccess, so extraction cannot reopen writes.
        $guard="\n# Shared rollout maintenance (retained by new runtime)\nRewriteEngine On\nRewriteCond %{DOCUMENT_ROOT}/CoffeyTableRecipes/storage/shared-rollout-maintenance -f\nRewriteCond %{REQUEST_URI} ^/CoffeyTableRecipes/api/v1\nRewriteCond %{REQUEST_URI} !^/CoffeyTableRecipes/api/v1/health$\nRewriteCond %{REQUEST_METHOD} !^OPTIONS$\nRewriteRule ^ - [R=503,L]\n";
        if(!str_contains(file_get_contents($root.'/.htaccess'),'shared-rollout-maintenance')){
            $guarded=$guard.file_get_contents($root.'/.htaccess');
            if(file_put_contents($root.'/.htaccess',$guarded,LOCK_EX)!==strlen($guarded))throw new RuntimeException('Cannot install maintenance guard.');
        }
        if(file_put_contents($marker,'Paused for verified shared recipe rollout',LOCK_EX)===false)throw new RuntimeException('Cannot enable maintenance.');
        echo "Paused. Verify API returns 503, confirm no other writers, and allow at least 300 seconds to drain before backup.\n";exit;
    }
    if(!is_file($marker))throw new RuntimeException('Maintenance marker missing.');
    $state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
    if(mediaHashes($root)!==$state['media'])throw new RuntimeException('Media changed since backup; retain maintenance and obtain a fresh verified backup before continuing.');
    if(webHashes($root)!==$state['web'])throw new RuntimeException('Web assets changed; stop and investigate.');
    if(!hash_equals($state['backend_backup_sha256'],hash_file('sha256',$dir.'/backend-media.zip')))throw new RuntimeException('Backend backup checksum mismatch.');
    $service=new SharedRecipeRollout($pdo,$dir,$root.'/database/migrations');
    if($phase==='backup'){
        if($state['phase']!=='paused'||time()-$state['paused_at']<300)throw new RuntimeException('Pause and drain first.');
        if(getenv('ROLLOUT_WRITERS_QUIESCED')!=='yes')throw new RuntimeException('Operator must verify no external writers and API maintenance, then set ROLLOUT_WRITERS_QUIESCED=yes.');
        if(!preg_match('/^'.preg_quote($account,'/').'_recipes_recovery_[a-f0-9]{8}$/D',$argument))throw new RuntimeException('Provide a dedicated empty account_recipes_recovery_XXXXXXXX database.');
        $backup=$service->backup();$state['backup']=$backup;recordState($dir,$state);
        $recoveryConfig=$config;$recoveryConfig['database']['name']=$argument;$service->restoreDrill(database($recoveryConfig),$backup);
        $state['backup']['restore_verified']=true;$state['recovery_database']=$argument;$state['phase']='backed_up';recordState($dir,$state);
    }elseif($phase==='apply'){
        if($state['phase']!=='backed_up')throw new RuntimeException('Verified backup required.');
        $service->apply($state['backup']);$state['applied_checksums']=[];foreach(SharedRecipeRollout::MIGRATIONS as $name)$state['applied_checksums'][$name]=hash_file('sha256',$root.'/database/migrations/'.$name);$state['phase']='migrated';recordState($dir,$state);
    }else{
        if(!in_array($state['phase'],['migrated','verified'],true))throw new RuntimeException('Migration verification required.');
        $service->verify($state['backup']);verifyRuntime($root,$argument);$state['phase']='verified';$state['deployed_commit']=$argument;recordState($dir,$state);
        if($phase==='resume'){
            if(getenv('ROLLOUT_PRIVILEGES_RESTORED')!=='yes')throw new RuntimeException('Verify original database privileges are restored before resuming.');
            if(!unlink($marker))throw new RuntimeException('Cannot remove maintenance marker.');$state['phase']='resumed';recordState($dir,$state);
        }
    }
    echo 'Verified phase: '.$state['phase'].". Backups/recovery database retained; no automatic cleanup or live restore.\n";
}catch(Throwable $error){fwrite(STDERR,'Rollout stopped: '.($error instanceof RuntimeException && !$error instanceof PDOException?$error->getMessage():get_class($error)).". Keep maintenance and purge paused; inspect protected state.\n");exit(1);}
