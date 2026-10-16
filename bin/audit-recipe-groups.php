<?php
declare(strict_types=1);
// Read-only inventory for the requested review; never exposed through HTTP.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$run=$argv[1] ?? ''; $account=$argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/',$run) || !preg_match('/^[a-z0-9_]+$/',$account)) exit(1);
$root=dirname(__DIR__); $receipt=$root.'/storage/grouping-audit-'.$run.'.json';
$lock=fopen($receipt.'.lock','c+');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(1);
try {
    if (is_file($receipt)) exit;
    $config=configuration();
    if ($root!=='/home/'.$account.'/coffeybean.com/CoffeyTableRecipes' || $config['database']['name']!==$account.'_recipes') throw new RuntimeException('Unexpected target');
    $pdo=database($config);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $recipes=$pdo->query('SELECT id,household_id,title,parent_recipe_id,revision FROM recipes WHERE deleted_at IS NULL ORDER BY title,id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
    if (count($recipes)>500) throw new RuntimeException('Review inventory limit exceeded');
    foreach ($recipes as &$recipe) {
        foreach (['ingredients'=>['recipe_ingredients','id,group_id,position,original_text'],
            'steps'=>['recipe_steps','position,section_title,instruction'],
            'groups'=>['recipe_ingredient_groups','id,position,title']] as $key=>[$table,$columns]) {
            $q=$pdo->prepare("SELECT $columns FROM $table WHERE recipe_id=? AND household_id=? ORDER BY position,id");
            $q->execute([$recipe['id'],$recipe['household_id']]);
            $recipe[$key]=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($recipe['household_id']);
    }
    unset($recipe);
    $pdo->commit();
    $report=['status'=>'ok','read_only'=>true,'recipe_count'=>count($recipes),'recipes'=>$recipes];
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $report=['status'=>'error','error'=>$error instanceof HttpError ? $error->errorCode : 'audit_failed'];
} finally {
    if (isset($report)) { file_put_contents($receipt,json_encode($report,JSON_THROW_ON_ERROR),LOCK_EX); chmod($receipt,0600); }
    flock($lock,LOCK_UN); fclose($lock);
}
