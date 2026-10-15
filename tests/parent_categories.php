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
echo "$checks parent category checks passed.\n";
