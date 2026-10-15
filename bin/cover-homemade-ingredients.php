<?php
declare(strict_types=1);
// One-time, CLI-only cover backfill requested for this exact parent category.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$run=$argv[1] ?? ''; $account=$argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/',$run) || !preg_match('/^[a-z0-9_]+$/',$account)) exit(1);
$root=dirname(__DIR__); $receipt=$root.'/storage/parent-cover-'.$run.'.json';
$lock=fopen($receipt.'.lock','c+');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(1);
try {
    if (is_file($receipt)) exit;
    $config=configuration();
    if ($root!=='/home/'.$account.'/coffeybean.com/CoffeyTableRecipes' || $config['database']['name']!==$account.'_recipes') throw new RuntimeException('Unexpected target');
    $pdo=database($config);
    $rows=$pdo->query("SELECT id,label,image_key FROM recipe_categories WHERE parent_id IS NULL AND LOWER(TRIM(label)) IN ('homemade ingrediants','homemade ingredients')")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) throw new RuntimeException('Expected exactly one matching parent category');
    $category=$rows[0]; $id=(string)$category['id'];
    $actor=$pdo->query("SELECT id FROM users WHERE account_role='administrator' AND disabled=0 ORDER BY id LIMIT 1")->fetchColumn();
    if ($actor===false) throw new RuntimeException('Enabled administrator required');
    $backup=$root.'/storage/before-parent-cover-'.$run.'.json';
    if (file_put_contents($backup,json_encode($category,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Backup failed');
    chmod($backup,0600);
    $url=rtrim(preg_replace('#/app/?$#','',$config['app_url']),'/').'/media/recipes';
    $images=new CategoryImageService($pdo,new RecipePhotoService($config['recipe_ai'],$root.'/storage'),$root.'/media/recipes',$url);
    $imageUrl=$images->ensureParent($id,(string)$actor);
    $path=$root.'/media/recipes/category-'.$id.'.jpg';
    if (!is_file($path) || !getimagesize($path)) throw new RuntimeException('Cover verification failed');
    $report=['status'=>'ok','category_id'=>$id,'label'=>$category['label'],'image_url'=>$imageUrl,'sha256'=>hash_file('sha256',$path)];
} catch (Throwable $error) {
    $report=['status'=>'error','error'=>$error instanceof HttpError ? $error->errorCode : 'cover_failed'];
} finally {
    if (isset($report)) {
        file_put_contents($receipt,json_encode($report,JSON_THROW_ON_ERROR),LOCK_EX);
        chmod($receipt,0600);
    }
    flock($lock,LOCK_UN); fclose($lock);
}
