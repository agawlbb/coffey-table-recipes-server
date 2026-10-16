<?php
declare(strict_types=1);
// Authorized, exact-recipe maintenance. Never available through HTTP.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$run=$argv[1] ?? ''; $account=$argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/',$run) || !preg_match('/^[a-z0-9_]+$/',$account)) exit(1);
$root=dirname(__DIR__); $receipt=$root.'/storage/breakfast-groups-'.$run.'.json';
$lock=fopen($receipt.'.lock','c+');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(1);
try {
    if (is_file($receipt)) exit;
    $config=configuration();
    if ($root!=='/home/'.$account.'/coffeybean.com/CoffeyTableRecipes' || $config['database']['name']!==$account.'_recipes') throw new RuntimeException('Unexpected target');
    $pdo=database($config);
    $matches=$pdo->query("SELECT * FROM recipes WHERE deleted_at IS NULL AND LOWER(TRIM(title))='breakfast ring'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches)!==1) throw new RuntimeException('Expected exactly one Breakfast Ring');
    $recipe=$matches[0]; $id=$recipe['id']; $household=$recipe['household_id'];
    $read=static function(string $table) use ($pdo,$id,$household): array {
        $q=$pdo->prepare("SELECT * FROM $table WHERE recipe_id=? AND household_id=? ORDER BY position,id");
        $q->execute([$id,$household]); return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $ingredients=$read('recipe_ingredients'); $steps=$read('recipe_steps'); $beforeGroups=$read('recipe_ingredient_groups');
    if (!$ingredients || !$steps || $beforeGroups) throw new RuntimeException('Expected an ungrouped recipe with ingredients and method');
    foreach ($ingredients as $ingredient) if ($ingredient['group_id']!==null) throw new RuntimeException('Recipe already has assignments');
    $source=['title'=>$recipe['title'],'ingredients'=>array_map(static fn($r)=>array_intersect_key($r,array_flip(['original_text','ingredient_name','quantity_text','quantity_decimal','quantity_numerator','quantity_denominator','original_unit','preparation'])), $ingredients),
        'steps'=>array_column($steps,'instruction'),'source_text'=>$recipe['source_text'] ?? null];
    $request="Organize this existing Breakfast Ring recipe into Dough and Sauce ingredient components. Preserve ingredient order, each original_text and ingredient_name, amounts and units exactly. Do not merge or split existing rows. Use exactly the group titles Dough and Sauce. Use the original source and method to determine membership; ask if ambiguous. Existing recipe: ".json_encode($source,JSON_THROW_ON_ERROR);
    $reply=(new RecipeImportService($config['recipe_ai'],$root.'/storage'))->analyze(['kind'=>'text','text'=>$request],'breakfast-groups-'.$run);
    $draft=$reply['recipe'];
    if ($reply['questions'] || count($draft['ingredients'])!==count($ingredients) || count($draft['groups'])!==2) throw new RuntimeException('AI grouping needs review');
    $titles=array_column($draft['groups'],'title','id');
    if (array_diff(['Dough','Sauce'],array_values($titles))) throw new RuntimeException('Unexpected component titles');
    $normalize=static fn($text)=>mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$text)));
    $amount=static function(array $r): ?float {
        if (is_numeric($r['quantity_decimal'] ?? null)) return (float)$r['quantity_decimal'];
        if (is_numeric($r['quantity_numerator'] ?? null) && (float)($r['quantity_denominator'] ?? 0)>0) return (float)$r['quantity_numerator']/(float)$r['quantity_denominator'];
        return null;
    };
    $assignments=[]; $summary=[];
    foreach ($ingredients as $i=>$ingredient) {
        $proposed=$draft['ingredients'][$i];
        if ($normalize($ingredient['original_text'])!==$normalize($proposed['original_text'])) {
            $a=$amount($ingredient); $b=$amount($proposed);
            if ($normalize($ingredient['ingredient_name'])!==$normalize($proposed['ingredient_name']) || $a===null || $b===null || abs($a-$b)>0.00005
                || rtrim($normalize($ingredient['original_unit']),'s')!==rtrim($normalize($proposed['original_unit']),'s')) throw new RuntimeException('AI changed or reordered an ingredient');
        }
        $title=$titles[$proposed['group_id'] ?? ''] ?? null;
        if ($title===null) throw new RuntimeException('Ungrouped ingredient');
        $assignments[$ingredient['id']]=$title;
        $summary[$title][]=$ingredient['original_text'];
    }
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT revision FROM recipes WHERE id=? AND household_id=? AND deleted_at IS NULL FOR UPDATE');
    $q->execute([$id,$household]);
    if ((string)$q->fetchColumn()!==(string)$recipe['revision'] || $read('recipe_ingredients')!==$ingredients || $read('recipe_ingredient_groups')!==$beforeGroups) throw new RuntimeException('Recipe changed during grouping');
    $backup=$root.'/storage/before-breakfast-groups-'.$run.'.json';
    if (file_put_contents($backup,json_encode(['recipe'=>$recipe,'ingredients'=>$ingredients,'groups'=>$beforeGroups,'assignments'=>$assignments],JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Backup failed');
    chmod($backup,0600);
    $insert=$pdo->prepare('INSERT INTO recipe_ingredient_groups (household_id,recipe_id,position,title) VALUES (?,?,?,?)');
    $update=$pdo->prepare('UPDATE recipe_ingredients SET group_id=? WHERE id=? AND recipe_id=? AND household_id=?');
    foreach (['Dough','Sauce'] as $position=>$title) {
        $insert->execute([$household,$id,$position+1,$title]); $groupId=$pdo->lastInsertId();
        foreach ($assignments as $ingredientId=>$groupTitle) if ($groupTitle===$title) $update->execute([$groupId,$ingredientId,$id,$household]);
    }
    // Grouping changes presentation, not nutrition; preserve existing freshness.
    $q=$pdo->prepare('UPDATE recipe_nutrition SET recipe_revision=recipe_revision+1 WHERE recipe_id=? AND household_id=? AND recipe_revision=?');
    $q->execute([$id,$household,$recipe['revision']]);
    $q=$pdo->prepare('UPDATE recipes SET revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND household_id=?'); $q->execute([$id,$household]);
    $after=$read('recipe_ingredients');
    foreach ($after as $i=>$row) { $row['group_id']=$ingredients[$i]['group_id']; if ($row!==$ingredients[$i]) throw new RuntimeException('Ingredient preservation failed'); }
    if ($read('recipe_steps')!==$steps) throw new RuntimeException('Instructions changed');
    $pdo->commit();
    $report=['status'=>'ok','recipe_id'=>(string)$id,'title'=>$recipe['title'],'groups'=>$summary,'ingredient_count'=>count($ingredients),'backup'=>basename($backup)];
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $report=['status'=>'error','error'=>$error instanceof HttpError ? $error->errorCode : $error->getMessage()];
} finally {
    if (isset($report)) { file_put_contents($receipt,json_encode($report,JSON_THROW_ON_ERROR),LOCK_EX); chmod($receipt,0600); }
    flock($lock,LOCK_UN); fclose($lock);
}
