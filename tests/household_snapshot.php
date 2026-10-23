<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
final class SnapshotPDO extends PDO { public int $reads=0; public function prepare(string $query,array $options=[]): PDOStatement|false { $this->reads++; return parent::prepare($query,$options); } }
$pdo=new SnapshotPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn($p)=>'2026-10-23');
foreach (['003_recipes.sql','005_recipe_categories.sql'] as $name) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(dirname(__DIR__).'/database/migrations/'.$name),$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $columns=[];
        foreach (explode("\n",$table[2]) as $line) {
            if (!preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) continue;
            $columns[]=$m[1].' '.($m[1]==='id'?'INTEGER PRIMARY KEY':(in_array($m[2],['BIGINT','INT','BOOLEAN'])?'INTEGER':'TEXT'));
        }
        if ($table[1]==='recipes') array_push($columns,'deleted_at TEXT','trash_household_id INTEGER','deleted_by_user_id INTEGER');
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
$pdo->exec("CREATE TABLE recipe_households (recipe_id INTEGER,household_id INTEGER,removed_at TEXT);
CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE household_people (id INTEGER,household_id INTEGER,user_id INTEGER,display_name TEXT);
CREATE TABLE household_invitations (person_id INTEGER,status TEXT,expires_at TEXT,email TEXT);

CREATE TABLE shopping_lists (household_id INTEGER,revision INTEGER,items TEXT);
INSERT INTO household_memberships VALUES (1,1,'owner'),(2,2,'owner');
INSERT INTO household_people VALUES (10,1,1,'Joe');
INSERT INTO recipes (id,household_id,title,revision,source_text) VALUES (1,1,'Soup',2,'PRIVATE SOURCE'),(2,2,'Other',1,'OTHER PRIVATE');
INSERT INTO recipe_households VALUES (1,1,NULL),(2,2,NULL);
INSERT INTO recipe_categories (id,label,position) VALUES (10,'Meals',1);
INSERT INTO recipe_categories (id,label,parent_id,position) VALUES (11,'Soups',10,2);
INSERT INTO recipe_category_assignments (household_id,recipe_id,category_id) VALUES (1,1,11);
INSERT INTO recipe_favorites (recipe_id,household_id,person_id) VALUES (1,1,10);
INSERT INTO recipe_steps (id,household_id,recipe_id,position,instruction) VALUES (1,1,1,1,'Simmer');");
if (class_exists('MealPlanService')) $pdo->exec("CREATE TABLE meal_plans(household_id INTEGER PRIMARY KEY,revision INTEGER,time_zone TEXT);
CREATE TABLE meal_plan_entries(household_id INTEGER,id TEXT,plan_date TEXT,kind TEXT,recipe_id INTEGER,recipe_identity INTEGER,title TEXT,recipe_revision INTEGER,created_at TEXT);
CREATE TABLE IF NOT EXISTS cooking_events(id INTEGER,household_id INTEGER,cooked_at TEXT);
CREATE TABLE IF NOT EXISTS cooking_event_recipes(household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_revision INTEGER);
CREATE TABLE deleted_recipe_cooking_history(household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_title TEXT,recipe_revision INTEGER);
INSERT INTO meal_plans VALUES (1,0,'America/New_York');");
$pdo->exec('CREATE TABLE household_restaurant_lists(household_id INTEGER PRIMARY KEY,revision INTEGER,items_json TEXT)');
$s=new HouseholdSnapshot($pdo,new HouseholdService($pdo),'https://example.com/media');
function verifySnapshot(bool $ok): void { if (!$ok) throw new RuntimeException('Snapshot assertion failed'); }
$result=$s->read('1','1');
verifySnapshot(count($result['recipes'])===1 && $result['recipes'][0]['id']==='1');
verifySnapshot($result['responses']['/households/1/recipes/1']['recipe']['steps'][0]['instruction']==='Simmer');
verifySnapshot(!isset($result['responses']['/households/1/recipes/2']));
verifySnapshot(!str_contains(json_encode($result),'PRIVATE SOURCE'));
verifySnapshot($result['responses']['/households/1/recipes/1/favorites']['people'][0]['favorite']===true);
verifySnapshot($s->read('1','1')['version']===$result['version']);
$pdo->exec("UPDATE recipe_steps SET instruction='Boil' WHERE id=1");
verifySnapshot($s->read('1','1')['version']!==$result['version']);
try { $s->read('2','1'); throw new RuntimeException('Unauthorized snapshot'); } catch (HttpError $e) { verifySnapshot($e->status===404); }
$pdo->reads=0; $s->read('1','1'); $reads=$pdo->reads;
for ($id=3;$id<=12;$id++) $pdo->exec("INSERT INTO recipes (id,household_id,title,revision) VALUES ($id,1,'Recipe $id',1); INSERT INTO recipe_households VALUES ($id,1,NULL)");
$pdo->reads=0; $larger=$s->read('1','1');
verifySnapshot(count($larger['recipes'])===11 && $pdo->reads===$reads);
echo "Household snapshot checks passed (fixed query count: $reads)\n";

