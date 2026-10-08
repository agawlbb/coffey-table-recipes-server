<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/RecipeImportTools.php';
$options=getopt('', ['dry-run','apply','bundle:','restore:','cleanup-existing']);
if(isset($options['dry-run'])===isset($options['apply']) && !isset($options['restore']))throw new RuntimeException('Specify exactly one of --dry-run or --apply.');
$config=configuration(); if($config['environment']!=='development')throw new RuntimeException('Development only');
$pdo=database($config);
$destination=$pdo->query("SELECT h.id,h.name,m.user_id,u.display_name FROM households h JOIN household_memberships m ON m.household_id=h.id JOIN users u ON u.id=m.user_id WHERE h.id=2 AND m.user_id=6 AND u.disabled=0 AND m.role IN ('owner','admin')")->fetch(PDO::FETCH_ASSOC);
if(!$destination || $destination['name']!=='Coffey')throw new RuntimeException('Importing membership changed');
$storage=dirname(__DIR__).'/storage';
function rows(PDO $pdo,string $table,int $id): array {
    $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE household_id=2 AND '.($table==='recipes'?'id':'recipe_id').'=? ORDER BY '.($table==='recipe_tags'?'kind,label':'id'));
    $q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function state(PDO $pdo,int $id): array {
    return ['recipe'=>rows($pdo,'recipes',$id)[0],'tags'=>rows($pdo,'recipe_tags',$id),'photos'=>rows($pdo,'recipe_photos',$id)];
}
function saveJson(string $path,array $value): void {
    $text=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents($path.'.tmp',$text,LOCK_EX)!==strlen($text) || !rename($path.'.tmp',$path))throw new RuntimeException('Cannot save recovery data');
}
function addRow(PDO $pdo,string $table,array $row): void {
    unset($row['cover_recipe_id']);
    $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
}
if(isset($options['restore'])){
    $path=realpath($options['restore']);
    if(!$path || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',realpath($storage)).'/'))throw new RuntimeException('Restore only from protected storage');
    $snapshot=json_decode(file_get_contents($path),true,100,JSON_THROW_ON_ERROR);
    foreach($snapshot['entries'] as $entry){
        if(!isset($entry['after']))continue;
        $id=(int)$entry['before']['recipe']['id'];
        if((int)$entry['before']['recipe']['household_id']!==2)throw new RuntimeException('Wrong restore household');
        $pdo->beginTransaction();
        try{
            $pdo->prepare('SELECT id FROM recipes WHERE household_id=2 AND id=? FOR UPDATE')->execute([$id]);
            if(state($pdo,$id)!==$entry['after'])throw new RuntimeException('Recipe edited since cleanup; refusing restore');
            $r=$entry['before']['recipe'];
            $pdo->prepare('UPDATE recipes SET yield_text=?,servings=?,notes=?,revision=?,updated_by_user_id=?,updated_at=? WHERE household_id=2 AND id=?')->execute([$r['yield_text'],$r['servings'],$r['notes'],$r['revision'],$r['updated_by_user_id'],$r['updated_at'],$id]);
            foreach(['recipe_tags'=>'tags','recipe_photos'=>'photos'] as $table=>$field){
                $pdo->prepare('DELETE FROM '.$table.' WHERE household_id=2 AND recipe_id=?')->execute([$id]);
                foreach($entry['before'][$field] as $row)addRow($pdo,$table,$row);
            }
            $pdo->commit();echo "Restored recipe $id\n";
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    exit;
}
$bundle=$options['bundle']??'C:/Users/developer/StudioProjects/coffey_table_recipes/recipe-import/import-ready.json';
$data=json_decode(ltrim(file_get_contents($bundle),"\xEF\xBB\xBF"),true,100,JSON_THROW_ON_ERROR);
if(count($data)!==8)throw new RuntimeException('Expected the reviewed eight-recipe bundle');
$cachePath=$storage.'/recipe-image-verification.json';
$cache=is_file($cachePath)?json_decode(file_get_contents($cachePath),true,100,JSON_THROW_ON_ERROR):[];
$report=['mode'=>isset($options['apply'])?'apply':'dry-run','destination'=>$destination,'recipes'=>[]];
$reportPath=$storage.'/recipe-cleanup-'.$report['mode'].'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
$snapshot=['created_at_utc'=>gmdate('c'),'entries'=>[]];
$snapshotPath=$storage.'/recipe-cleanup-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
foreach($data as $local){
    $key=hash('sha256',$local['source_url']);
    $q=$pdo->prepare('SELECT * FROM recipes WHERE household_id=2 AND import_key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r || $r['source_url']!==$local['source_url'])throw new RuntimeException('Source identity mismatch');
    $id=(int)$r['id'];$source=json_decode($r['source_text'],true,100,JSON_THROW_ON_ERROR);
    if(($source['source_url']??null)!==$r['source_url'] || ($source['image_source']??null)!==($local['image_source']??null))throw new RuntimeException('Original extraction mismatch');
    $before=state($pdo,$id);$changes=[];$fields=[];$remove=[];$issues=[];
    if($r['notes']===RecipeImportTools::BOOKKEEPING){$fields['notes']=null;$changes[]=['field'=>'notes','old'=>$r['notes'],'new'=>null,'reason'=>'Exact importer bookkeeping; provenance remains in source_text','evidence'=>'Importer constant and stored source_text'];}
    elseif($r['notes']!==null && str_contains($r['notes'],RecipeImportTools::BOOKKEEPING))$issues[]='Mixed cooking and importer notes require review';
    $steps=rows($pdo,'recipe_steps',$id);
    $yield=RecipeImportTools::yieldValue($r['yield_text'],array_column($steps,'instruction'),$r['source_url']);
    if($yield!==$r['yield_text']){
        $fields['yield_text']=$yield;
        $changes[]=['field'=>'yield_text','old'=>$r['yield_text'],'new'=>$yield,'reason'=>'Explicit original muffin instruction or redundant equivalent numeric yield','evidence'=>['source_url'=>$r['source_url'],'original_yield'=>$source['yield'],'instruction_evidence'=>str_contains($yield??'','muffins')?array_column($steps,'instruction')[count($steps)-1]:null]];
        if($yield==='15 medium muffins' && $r['servings']!==null){$fields['servings']=null;$changes[]=['field'=>'servings','old'=>$r['servings'],'new'=>null,'reason'=>'Muffin count does not establish people served','evidence'=>$yield];}
    }
    if($r['yield_text']!==null && str_contains($r['yield_text'],';') && $yield===$r['yield_text'])$issues[]='Ambiguous yield retained: '.$r['yield_text'];
    foreach($before['tags'] as $tag){
        $reason=$tag['kind']==='category'?RecipeImportTools::excludedCategory($r['source_url'],$tag['label']):null;
        if($reason){$remove[]=$tag;$changes[]=['field'=>'category','old'=>$tag['label'],'new'=>null,'reason'=>$reason,'evidence'=>['source_url'=>$r['source_url'],'original_categories'=>$source['category']]];}
    }
    $verified=[];
    foreach(RecipeImportTools::images($source['image_source']??null,$r['source_url']) as $candidate){
        $url=$candidate['source_url'];$cacheKey=hash('sha256',$url);
        if(!isset($cache[$cacheKey]) || time()-($cache[$cacheKey]['checked_at']??0)>3600){
            try{$cache[$cacheKey]=['checked_at'=>time(),'image'=>RecipeImportTools::verifyImage($url)];}
            catch(Throwable $e){$cache[$cacheKey]=['checked_at'=>time(),'error'=>$e->getMessage()];}
            saveJson($cachePath,$cache);
        }
        if(isset($cache[$cacheKey]['image']))$verified[]=array_merge($cache[$cacheKey]['image'],['caption'=>$candidate['caption']]);
        else $issues[]=['image'=>$url,'failure'=>$cache[$cacheKey]['error']];
    }
    $attribution=$r['source_author']?:parse_url($r['source_url'],PHP_URL_HOST);
    $photos=RecipeImportTools::pendingPhotos($before['photos'],$verified,$attribution);
    foreach($photos as $photo)$changes[]=['field'=>'photo','old'=>null,'new'=>$photo,'reason'=>'Verified original Recipe.image reference','evidence'=>['recipe_url'=>$r['source_url'],'verification'=>array_values(array_filter($verified,static fn($i)=>$i['source_url']===$photo['source_url']))[0]]];
    if(isset($options['apply']) && $changes){
        $snapshot['entries'][]=['before'=>$before];$index=count($snapshot['entries'])-1;saveJson($snapshotPath,$snapshot);
        $pdo->beginTransaction();
        try{
            $pdo->prepare('SELECT id FROM recipes WHERE household_id=2 AND id=? FOR UPDATE')->execute([$id]);
            if(state($pdo,$id)!==$before)throw new RuntimeException('Concurrent recipe edit; rerun dry-run');
            foreach($remove as $tag)$pdo->prepare('DELETE FROM recipe_tags WHERE household_id=2 AND recipe_id=? AND kind=? AND label=?')->execute([$id,$tag['kind'],$tag['label']]);
            foreach($photos as $photo)addRow($pdo,'recipe_photos',['household_id'=>2,'recipe_id'=>$id]+$photo);
            $set=[];$values=[];foreach($fields as $field=>$value){$set[]=$field.'=?';$values[]=$value;}
            $set[]='revision=revision+1';$set[]='updated_by_user_id=?';$values[]=6;$values[]=$id;$values[]=$r['revision'];
            $condition='';
            if(array_key_exists('notes',$fields)){$condition=' AND notes=?';$values[]=RecipeImportTools::BOOKKEEPING;}
            $stmt=$pdo->prepare('UPDATE recipes SET '.implode(',',$set).' WHERE household_id=2 AND id=? AND revision=?'.$condition);$stmt->execute($values);
            if($stmt->rowCount()!==1)throw new RuntimeException('Revision conflict');
            $after=state($pdo,$id);
            // Persist expected recovery state before committing, including exact affected rows.
            $snapshot['entries'][$index]['after']=$after;saveJson($snapshotPath,$snapshot);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    $report['recipes'][]=['id'=>$id,'title'=>$r['title'],'changes'=>$changes,'issues'=>$issues,'verified_images'=>count($verified)];
    echo $id.' '.$r['title'].': '.count($changes).' changes, '.count($photos).' new photos, '.count($issues)." issues\n";
    saveJson($storage.'/recipe-cleanup-'.$report['mode'].'.json',$report);
    saveJson($reportPath,$report);
}
if($snapshot['entries'])echo 'Recovery snapshot: '.$snapshotPath.PHP_EOL;
echo 'Report: '.$storage.'/recipe-cleanup-'.$report['mode'].'.json'.PHP_EOL;
