<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/RecipeShare.php';

// In-memory fixtures only: never connects to a local or hosted recipe database.
// SQL adaptations cover timestamps/locking syntax, not MySQL concurrency or DDL.
final class TrashFixtureStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        try { return parent::execute($params); }
        catch (PDOException $error) {
            // Translate only the production import-key uniqueness constraint.
            if (str_contains($error->getMessage(), 'recipes.household_id, recipes.import_key')) $error->errorInfo[1] = 1062;
            throw $error;
        }
    }
}
final class TrashFixtureDatabase extends PDO {
    private function sql(string $sql): string {
        return str_replace(['DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY)','UTC_TIMESTAMP(6)',' FOR UPDATE',' <=> '], ["datetime('now','+30 days')","datetime('now')",'',' IS '], $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query),$options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return parent::query($this->sql($query),$fetchMode,...$args); }
}
$pdo = new TrashFixtureDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[TrashFixtureStatement::class]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
 CREATE TABLE households (id INTEGER,ai_images_enabled INTEGER);
 INSERT INTO households VALUES (1,0),(2,0);
 INSERT INTO household_memberships VALUES (1,1,'owner'),(1,3,'member'),(2,2,'owner');");
// Retain the real recipe column names and child foreign keys in a SQLite fixture.
foreach (['003_recipes.sql','005_recipe_categories.sql'] as $name) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(dirname(__DIR__).'/database/migrations/'.$name),$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $columns = [];
        foreach (explode("\n",$table[2]) as $line) {
            if (!preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) continue;
            $columns[] = $m[1].' '.($m[2]==='BIGINT' || $m[2]==='INT' || $m[2]==='BOOLEAN' ? 'INTEGER' : 'TEXT').($m[1]==='revision' ? ' DEFAULT 1' : '');
        }
        if ($table[1]==='recipes') {
            $columns = array_filter($columns,fn($c)=>!str_starts_with($c,'id '));
            array_unshift($columns,'id INTEGER PRIMARY KEY');
            array_push($columns,'deleted_at TEXT','purge_after TEXT','deleted_by_user_id INTEGER','trash_household_id INTEGER','UNIQUE(household_id,id)','FOREIGN KEY (parent_recipe_id) REFERENCES recipes(id)');
        } elseif (!in_array($table[1],['recipe_categories','cooking_events'],true)) {
            // Fixture cleanup mirrors the production recipe cascades.
            $columns[] = 'FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE';
        }
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
$pdo->exec('CREATE UNIQUE INDEX household_import_key ON recipes(household_id,import_key)');
$pdo->exec('CREATE TABLE deleted_recipe_cooking_history (household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_title TEXT,recipe_revision INTEGER,servings_made TEXT,notes TEXT,PRIMARY KEY(event_id,recipe_id))');
$pdo->exec("INSERT INTO recipes(id,household_id,title,revision) VALUES (1,1,'Soup',1),(2,1,'Bread',1),(3,2,'Private',1);
 INSERT INTO recipe_steps(id,household_id,recipe_id,position,instruction) VALUES (1,1,1,0,'Simmer');
 INSERT INTO recipe_categories VALUES(1,NULL,'meals','Meals','meals',0),(2,1,'soups','Soups','soups',0);
 INSERT INTO recipe_category_assignments VALUES(1,1,2);
 INSERT INTO recipe_pairings VALUES(1,1,2,'Serve together');
 INSERT INTO cooking_event_recipes VALUES(1,1,1,1,'2','Good soup');
 UPDATE recipes SET parent_recipe_id=1 WHERE id=2;");
$pdo->exec('CREATE TABLE recipe_relationship_lock(id INTEGER PRIMARY KEY); INSERT INTO recipe_relationship_lock VALUES(1)');
$pdo->exec('CREATE TABLE recipe_households(recipe_id INTEGER,household_id INTEGER,removed_at TEXT,PRIMARY KEY(recipe_id,household_id),FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE)');
$pdo->exec('INSERT INTO recipe_households(recipe_id,household_id) SELECT id,household_id FROM recipes');
$households = new HouseholdService($pdo); $trash = new RecipeDeletionService($pdo,$households); $read = new RecipeService($pdo,$households);
$checks=0;
function checkShared(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function rejectShared(callable $action,int $status): void { try { $action(); } catch (HttpError $e) { checkShared($e->status===$status,'Wrong rejection: '.$e->getMessage()); return; } throw new RuntimeException('Unauthorized action accepted'); }
$links=new RecipeMembership($pdo,$households);
checkShared($read->detail('','2','1',true)['in_household']===false,'Public foreign detail');
rejectShared(fn()=>$links->attach('1','2','1'),404);
$links->attach('2','2','1'); $links->attach('2','2','1');
checkShared((int)$pdo->query('SELECT COUNT(*) FROM recipe_households WHERE recipe_id=1')->fetchColumn()===2,'Attach must be idempotent');
checkShared($read->detail('2','2','1')['household_count']===2,'Same shared recipe');
checkShared(count($read->browse('','2','',0,'','',false,false,true)['recipes'])===2,'Public household list includes attached recipe');
$editor=new RecipeEditorService($pdo,$households,sys_get_temp_dir(),'https://example.test');
$draft=$read->detail('2','2','1');
rejectShared(fn()=>$editor->save('2','2','1',$draft),409);
$draft['confirm_shared_edit']=true;
$draft['original_language']='en'; $draft['measurement_system']='unspecified';
$draft['title']='Shared soup';
$updated=$editor->save('2','2','1',$draft);
checkShared($read->detail('1','1','1')['title']==='Shared soup','Cross-household edit changes the same recipe');
checkShared($read->detail('2','2','1')['id']==='1','Edit must not copy recipe');
rejectShared(fn()=>$editor->save('2','2','1',$draft),409);
$removed=$trash->delete('1','1','1',['revision'=>'2']);
checkShared($removed['purge_after']===null,'Non-final removal must not start purge');
checkShared($read->detail('2','2','1')['title']==='Shared soup','Other household keeps recipe');
rejectShared(fn()=>$read->detail('1','1','1'),404);
$last=$trash->delete('2','2','1',['revision'=>'2']);
checkShared($last['purge_after']!==null,'Final removal needs recovery period');
checkShared(count($trash->recentlyDeleted('2','2')['recipes'])===1,'Trash belongs to last household');
rejectShared(fn()=>$trash->restore('1','1','1'),404);
rejectShared(fn()=>$links->attach('1','1','1'),404);
$trash->restore('2','2','1');
checkShared($read->detail('2','2','1')['household_count']===1,'Restore must not resurrect removed households');
rejectShared(fn()=>$trash->delete('1','2','1',['revision'=>'3']),404);
echo "Shared recipe checks: $checks passed\n";
