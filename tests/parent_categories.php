<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

// Isolated in-memory fixtures; no persistent recipe database is accessed.
final class CategoryFixture extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
$pdo=new CategoryFixture('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys=ON;
CREATE TABLE users(id INTEGER PRIMARY KEY,account_role TEXT,disabled INTEGER);
INSERT INTO users VALUES(1,'administrator',0),(2,'user',0),(3,'administrator',1);
CREATE TABLE recipe_categories(id INTEGER PRIMARY KEY AUTOINCREMENT,parent_id INTEGER REFERENCES recipe_categories(id),slug TEXT UNIQUE,label TEXT COLLATE NOCASE,image_key TEXT,position INTEGER);
CREATE TABLE recipe_category_assignments(category_id INTEGER REFERENCES recipe_categories(id),recipe_id INTEGER,household_id INTEGER);
INSERT INTO recipe_categories VALUES(1,NULL,'meals','Meals','meals',1),(2,1,'soup','Soup','soup',1),(3,NULL,'desserts','Desserts','desserts',2);
INSERT INTO recipe_category_assignments VALUES(3,99,42);");
$service=new AdministrationService($pdo,static function(){});
$checks=0;
function expectCategory(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function rejectCategory(callable $call,int $status): void { try {$call();} catch(HttpError $e) {expectCategory($e->status===$status,'Wrong rejection status');return;} throw new RuntimeException('Expected rejection'); }
foreach (['2','3','999'] as $actor) {
    rejectCategory(fn()=>$service->parentCategories($actor),403);
    foreach (['add','rename','delete'] as $action) rejectCategory(fn()=>$service->parentCategories($actor,['action'=>$action,'id'=>'3','label'=>'Other']),403);
}
$rows=$service->parentCategories('1')['categories'];
expectCategory(count($rows)===2 && !$rows[0]['can_delete'] && !$rows[1]['can_delete'],'Nonempty inventory');
foreach (['1','3'] as $id) rejectCategory(fn()=>$service->parentCategories('1',['action'=>'delete','id'=>$id]),409);
rejectCategory(fn()=>$service->parentCategories('1',['action'=>'delete','id'=>'2']),404);
rejectCategory(fn()=>$service->parentCategories('1',['action'=>'rename','id'=>'999','label'=>'Missing']),404);
foreach (['',str_repeat('x',121),[],null] as $label) rejectCategory(fn()=>$service->parentCategories('1',['action'=>'add','label'=>$label]),422);
rejectCategory(fn()=>$service->parentCategories('1',['action'=>'add','label'=>'MEALS']),409);
rejectCategory(fn()=>$service->parentCategories('1',['action'=>'rename','id'=>'3','label'=>'Meals']),409);
rejectCategory(fn()=>$service->parentCategories('1',['action'=>'unknown']),422);
$rows=$service->parentCategories('1',['action'=>'add','label'=>'  Snacks  '])['categories'];
$snack=array_values(array_filter($rows,fn($c)=>$c['label']==='Snacks'))[0];
expectCategory($snack['can_delete'] && is_string($snack['id']),'New parent is empty');
$service->parentCategories('1',['action'=>'rename','id'=>'1','label'=>'Dinners']);
expectCategory($pdo->query('SELECT label FROM recipe_categories WHERE id=1')->fetchColumn()==='Dinners','Rename failed');
expectCategory((int)$pdo->query('SELECT parent_id FROM recipe_categories WHERE id=2')->fetchColumn()===1,'Rename changed child links');
$service->parentCategories('1',['action'=>'delete','id'=>$snack['id']]);
expectCategory(count($service->parentCategories('1')['categories'])===2,'Empty delete failed');
expectCategory((int)$pdo->query('SELECT COUNT(*) FROM recipe_category_assignments')->fetchColumn()===1,'Assignments removed');
$directory=sys_get_temp_dir().'/parent-cover-test-'.bin2hex(random_bytes(8));
$calls=0;
$jpeg=base64_encode(file_get_contents(__DIR__.'/fixtures/category-cover.jpg'));
$photo=new RecipePhotoService(['api_key'=>'fixture-only'],$directory,function($payload) use (&$calls,$jpeg): array {
    $calls++;
    expectCategory(str_contains($payload['input'],'parent category') && str_contains($payload['input'],'homemade pantry staples'),'Parent prompt lacks category context');
    expectCategory($payload['tools'][0]['quality']==='low' && $payload['tools'][0]['size']==='816x816','Image cost controls changed');
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>$jpeg]]])];
});
$images=new CategoryImageService($pdo,$photo,$directory,'https://example.test/media/recipes');
$withImages=new AdministrationService($pdo,static function(){},null,$images,'https://example.test/media/recipes');
try {
    foreach (['2','3','999'] as $actor) rejectCategory(fn()=>$images->ensureParent('1',$actor),403);
    rejectCategory(fn()=>$images->ensureParent('2','1'),404);
    rejectCategory(fn()=>$images->ensureParent('../1','1'),422);
    rejectCategory(fn()=>$images->ensureParent('1','1'),409);
    expectCategory($calls===0,'Unauthorized or existing category incurred image cost');
    $rows=$withImages->parentCategories('1',['action'=>'add','label'=>'Homemade ingredients'])['categories'];
    $parent=array_values(array_filter($rows,fn($c)=>$c['label']==='Homemade ingredients'))[0];
    $id=$parent['id'];
    expectCategory($parent['image_url']==='https://example.test/media/recipes/category-'.$id.'.jpg' && !$parent['needs_ai_image'],'Added parent cover missing');
    expectCategory(is_file($directory.'/category-'.$id.'.jpg') && $calls===1,'Cover not generated once');
    expectCategory($images->ensureParent($id,'1')===$parent['image_url'] && $calls===1,'Cover retry incurred another image charge');
    $lock=fopen($directory.'/category-'.$id.'.jpg.lock','c+'); flock($lock,LOCK_EX);
    rejectCategory(fn()=>$images->ensureParent($id,'1'),409);
    flock($lock,LOCK_UN); fclose($lock);
    $withImages->parentCategories('1',['action'=>'rename','id'=>$id,'label'=>'Pantry']);
    expectCategory($calls===1,'Rename regenerated artwork');
    $failurePhoto=new RecipePhotoService(['api_key'=>'fixture-only'],$directory,fn($p)=>['status'=>500,'body'=>'{}']);
    $failureImages=new CategoryImageService($pdo,$failurePhoto,$directory,'https://example.test/media/recipes');
    $failureAdmin=new AdministrationService($pdo,static function(){},null,$failureImages);
    $failed=$failureAdmin->parentCategories('1',['action'=>'add','label'=>'Failed cover']);
    $pending=array_values(array_filter($failed['categories'],fn($c)=>$c['label']==='Failed cover'))[0];
    expectCategory(is_string($failed['cover_warning']) && $pending['needs_ai_image'] && $pending['image_url']===null,'Failed cover lost category or retry state');
    $images->ensureParent($pending['id'],'1');
    expectCategory($calls===2,'Missing cover could not be retried');
    // Existing household child-image permission remains independent of admin generation.
    $pdo->exec("CREATE TABLE household_memberships(household_id INTEGER,user_id INTEGER,role TEXT);
        CREATE TABLE households(id INTEGER,ai_images_enabled INTEGER);
        CREATE TABLE recipes(id INTEGER PRIMARY KEY,deleted_at TEXT);
        CREATE TABLE recipe_households(recipe_id INTEGER,household_id INTEGER,removed_at TEXT);
        INSERT INTO recipes VALUES(100,NULL);
        INSERT INTO recipe_households VALUES(100,42,NULL);
        INSERT INTO households VALUES(42,0);
        INSERT INTO household_memberships VALUES(42,2,'owner');
        UPDATE recipe_categories SET slug='import-soup' WHERE id=2;
        INSERT INTO recipe_category_assignments VALUES(2,100,42);");
    rejectCategory(fn()=>$images->ensure('2','2','42'),403);
    expectCategory($calls===2,'Disabled household incurred child image cost');
} finally {
    foreach (glob($directory.'/*') ?: [] as $path) unlink($path);
    if (is_dir($directory)) rmdir($directory);
}
echo "$checks parent category checks passed.\n";
