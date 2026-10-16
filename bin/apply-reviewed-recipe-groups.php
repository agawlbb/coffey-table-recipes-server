<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/reviewed-recipe-groups-plan.php';
$run=$argv[1] ?? ''; $account=$argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/',$run) || !preg_match('/^[a-z0-9_]+$/',$account)) exit(1);
$root=dirname(__DIR__); $receipt=$root.'/storage/reviewed-groups-'.$run.'.json';
$lock=fopen($receipt.'.lock','c+');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(1);
try {
    if (is_file($receipt)) exit;
    $config=configuration();
    if ($root!=='/home/'.$account.'/coffeybean.com/CoffeyTableRecipes' || $config['database']['name']!==$account.'_recipes') throw new RuntimeException('Unexpected target');
    $pdo=database($config); $pdo->beginTransaction(); $before=[]; $reportRecipes=[];
    $read=static function(string $table, $id, $household, bool $ordered=true) use ($pdo): array {
        $q=$pdo->prepare("SELECT * FROM $table WHERE recipe_id=? AND household_id=?".($ordered?' ORDER BY position,id':''));
        $q->execute([$id,$household]); return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    foreach (reviewedRecipeGroupsPlan() as $id=>$plan) {
        $q=$pdo->prepare('SELECT * FROM recipes WHERE id=? AND deleted_at IS NULL FOR UPDATE'); $q->execute([$id]); $recipe=$q->fetch(PDO::FETCH_ASSOC);
        if (!$recipe || (int)$recipe['revision']!==$plan['revision']) throw new RuntimeException('Reviewed recipe revision changed: '.$id);
        $household=$recipe['household_id'];
        $ingredients=$read('recipe_ingredients',$id,$household); $steps=$read('recipe_steps',$id,$household); $groups=$read('recipe_ingredient_groups',$id,$household);
        $fingerprint=hash('sha256',implode("\n",array_column($ingredients,'original_text'))."\n--METHOD--\n".implode("\n",array_column($steps,'instruction')));
        if ($groups || count($ingredients)!==$plan['count'] || !hash_equals($plan['fingerprint'],$fingerprint)) throw new RuntimeException('Reviewed recipe content changed: '.$id);
        foreach ($ingredients as $ingredient) if ($ingredient['group_id']!==null) throw new RuntimeException('Unexpected existing grouping');
        $before[$id]=['recipe'=>$recipe,'ingredients'=>$ingredients,'steps'=>$steps,'groups'=>$groups,'nutrition'=>$read('recipe_nutrition',$id,$household,false)];
    }
    $backup=$root.'/storage/before-reviewed-groups-'.$run.'.json';
    if (file_put_contents($backup,json_encode($before,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Backup failed'); chmod($backup,0600);
    foreach (reviewedRecipeGroupsPlan() as $id=>$plan) {
        $original=$before[$id]; $household=$original['recipe']['household_id'];
        $insertIngredient=static function(array $values) use ($pdo): void {
            unset($values['id']);
            $columns=array_keys($values);
            $q=$pdo->prepare('INSERT INTO recipe_ingredients (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')'); $q->execute(array_values($values));
        };
        if ($id===24) {
            $egg=$original['ingredients'][6];
            $q=$pdo->prepare("UPDATE recipe_ingredients SET original_text='3 egg yolks',ingredient_name='egg yolks',quantity_text='3',quantity_decimal=3,quantity_numerator=NULL,quantity_denominator=NULL,quantity_max_decimal=NULL,original_unit=NULL,unit_code=NULL,preparation=NULL WHERE id=? AND recipe_id=? AND household_id=?"); $q->execute([$egg['id'],$id,$household]);
            // Free position 11 without violating the unique position constraint.
            $pdo->prepare('UPDATE recipe_ingredients SET position=position+1000 WHERE recipe_id=? AND household_id=? AND position>=11')->execute([$id,$household]);
            $pdo->prepare('UPDATE recipe_ingredients SET position=position-999 WHERE recipe_id=? AND household_id=? AND position>=1011')->execute([$id,$household]);
            $egg=array_replace($egg,['position'=>11,'original_text'=>'3 egg whites','ingredient_name'=>'egg whites','quantity_text'=>'3','quantity_decimal'=>'3','quantity_numerator'=>null,'quantity_denominator'=>null,'quantity_max_decimal'=>null,'original_unit'=>null,'unit_code'=>null,'preparation'=>null]);
            $insertIngredient($egg);
            $notes=trim((string)($original['recipe']['notes'] ?? ''));
            $notes=($notes===''?'':$notes."\n\n").'Separate 3 eggs: use the yolks in the lime filling and the whites in the meringue.';
            $pdo->prepare('UPDATE recipes SET notes=? WHERE id=? AND household_id=?')->execute([$notes,$id,$household]);
        }
        if ($id===18) {
            $pdo->prepare('UPDATE recipe_ingredients SET position=position+1000 WHERE recipe_id=? AND household_id=?')->execute([$id,$household]);
            $pdo->prepare('UPDATE recipe_ingredients SET position=position-997 WHERE recipe_id=? AND household_id=?')->execute([$id,$household]);
            foreach ([['1 cup sugar','sugar','cup'],['1 cup water','water','cup'],['1 bunch fresh mint','fresh mint','bunch']] as $i=>[$line,$name,$unit]) {
                $insertIngredient(['household_id'=>$household,'recipe_id'=>$id,'group_id'=>null,'position'=>$i+1,'original_text'=>$line,'ingredient_name'=>$name,'quantity_text'=>'1','quantity_decimal'=>'1','quantity_numerator'=>null,'quantity_denominator'=>null,'quantity_max_decimal'=>null,'original_unit'=>$unit,'unit_code'=>null,'preparation'=>null,'is_optional'=>0,'is_main_ingredient'=>0,'scale_quantity'=>1]);
            }
            $q=$pdo->prepare('UPDATE recipe_steps SET instruction=? WHERE recipe_id=? AND household_id=? AND position=?');
            $q->execute(['For one batch of mint syrup, bring 1 cup sugar and 1 cup water to a boil.',$id,$household,1]);
            $q->execute(['Add 1 bunch of mint.',$id,$household,4]);
            $note='The mint syrup ingredients make a batch. Use only 1/2 oz of the finished syrup per drink and refrigerate the rest. The cocktail ingredient amounts are for one drink.';
            $notes=trim((string)($original['recipe']['notes'] ?? '')); $notes=($notes===''?'':$notes."\n\n").$note;
            $pdo->prepare('UPDATE recipes SET notes=?,servings=1,yield_text=? WHERE id=? AND household_id=?')->execute([$notes,'1 cocktail, plus extra mint syrup',$id,$household]);
        }
        $insert=$pdo->prepare('INSERT INTO recipe_ingredient_groups (household_id,recipe_id,position,title) VALUES (?,?,?,?)');
        $assign=$pdo->prepare('UPDATE recipe_ingredients SET group_id=? WHERE recipe_id=? AND household_id=? AND position=?');
        $counts=[]; $groupPosition=0;
        foreach ($plan['groups'] as $title=>$positions) {
            $insert->execute([$household,$id,++$groupPosition,$title]); $groupId=$pdo->lastInsertId();
            foreach ($positions as $position) { $assign->execute([$groupId,$id,$household,$position]); if ($assign->rowCount()!==1) throw new RuntimeException('Missing planned ingredient'); }
            $counts[$title]=count($positions);
        }
        $after=$read('recipe_ingredients',$id,$household); $afterSteps=$read('recipe_steps',$id,$household);
        if (!in_array($id,[18,24],true)) {
            foreach ($after as $i=>$row) { $row['group_id']=$original['ingredients'][$i]['group_id']; if ($row!==$original['ingredients'][$i]) throw new RuntimeException('Ingredient changed unexpectedly'); }
        }
        if ($id!==18 && $afterSteps!==$original['steps']) throw new RuntimeException('Method changed unexpectedly');
        if ($id===24 && (count($after)!==13 || $after[6]['original_text']!=='3 egg yolks' || $after[10]['original_text']!=='3 egg whites')) throw new RuntimeException('Egg separation failed');
        if ($id===18 && count($after)!==7) throw new RuntimeException('Syrup cleanup failed');
        if (in_array($id,[18,24],true)) {
            $byId=array_column($after,null,'id');
            foreach ($original['ingredients'] as $old) {
                $row=$byId[$old['id']] ?? null;
                if ($row===null) throw new RuntimeException('Existing ingredient lost');
                $expectedPosition=(int)$old['position']+($id===18?3:((int)$old['position']>=11?1:0));
                if ((int)$row['position']!==$expectedPosition) throw new RuntimeException('Cleanup changed ingredient order');
                $allowed=['group_id','position'];
                if ($id===24 && (int)$old['position']===7) $allowed=array_merge($allowed,['original_text','ingredient_name','quantity_text','quantity_decimal','quantity_numerator','quantity_denominator','quantity_max_decimal','original_unit','unit_code','preparation']);
                foreach ($allowed as $key) $row[$key]=$old[$key];
                if ($row!==$old) throw new RuntimeException('Cleanup changed an unrelated ingredient field');
            }
            if ($id===18) foreach ($afterSteps as $i=>$step) {
                if (in_array((int)$step['position'],[1,4],true)) $step['instruction']=$original['steps'][$i]['instruction'];
                if ($step!==$original['steps'][$i]) throw new RuntimeException('Cleanup changed an unrelated method field');
            }
        }
        // Group-only edits preserve nutrition freshness. Cleanup edits leave it stale.
        if (!in_array($id,[18,24],true)) $pdo->prepare('UPDATE recipe_nutrition SET recipe_revision=recipe_revision+1 WHERE recipe_id=? AND household_id=? AND recipe_revision=?')->execute([$id,$household,$original['recipe']['revision']]);
        $pdo->prepare('UPDATE recipes SET revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND household_id=?')->execute([$id,$household]);
        $reportRecipes[]=['id'=>$id,'title'=>$original['recipe']['title'],'groups'=>$counts,'ungrouped'=>count(array_filter($after,static fn($r)=>$r['group_id']===null)),'ingredient_count'=>count($after)];
    }
    $pdo->commit();
    $report=['status'=>'ok','recipe_count'=>count($reportRecipes),'recipes'=>$reportRecipes,'backup'=>basename($backup)];
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $report=['status'=>'error','error'=>$error instanceof HttpError ? $error->errorCode : $error->getMessage()];
} finally {
    if (isset($report)) { file_put_contents($receipt,json_encode($report,JSON_THROW_ON_ERROR),LOCK_EX); chmod($receipt,0600); }
    flock($lock,LOCK_UN); fclose($lock);
}
