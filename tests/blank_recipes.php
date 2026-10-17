<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

// Disposable in-memory fixtures only. No configuration, network or hosted DB.
// SQLite checks behavior and lock SQL ordering, not InnoDB blocking semantics.
final class BlankRecipeStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        try { return parent::execute($params); }
        catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'recipes.household_id, recipes.import_key')) $error->errorInfo[1] = 1062;
            throw $error;
        }
    }
}
final class BlankRecipeDatabase extends PDO {
    public array $queries = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return parent::prepare(str_replace([' FOR UPDATE',' <=> ','UTC_TIMESTAMP(6)','INSERT IGNORE'],['',' IS ',"datetime('now')",'INSERT OR IGNORE'],$query),$options);
    }
}
$pdo = new BlankRecipeDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[BlankRecipeStatement::class]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
 CREATE TABLE households (id INTEGER PRIMARY KEY,ai_images_enabled INTEGER);
 INSERT INTO households VALUES (1,0),(2,0);
 INSERT INTO household_memberships VALUES (1,1,'owner'),(1,3,'member'),(2,2,'owner');");
foreach (['003_recipes.sql','005_recipe_categories.sql'] as $name) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(dirname(__DIR__).'/database/migrations/'.$name),$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $columns = [];
        foreach (explode("\n",$table[2]) as $line) {
            if (!preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) continue;
            if ($m[1]==='id') { $columns[] = 'id INTEGER PRIMARY KEY'; continue; }
            $columns[] = $m[1].' '.(in_array($m[2],['BIGINT','INT','BOOLEAN'],true) ? 'INTEGER' : 'TEXT').($m[1]==='revision' ? ' DEFAULT 1' : '');
        }
        if ($table[1]==='recipes') {
            array_push($columns,'deleted_at TEXT','purge_after TEXT','deleted_by_user_id INTEGER','trash_household_id INTEGER','UNIQUE(household_id,id)',
                'UNIQUE(household_id,import_key)','FOREIGN KEY (parent_recipe_id) REFERENCES recipes(id)');
        } elseif ($table[1]==='recipe_pairings') {
            $columns[]='FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE';
            $columns[]='FOREIGN KEY (paired_recipe_id) REFERENCES recipes(id) ON DELETE CASCADE';
        } elseif (!in_array($table[1],['recipe_categories','cooking_events'],true)) {
            $columns[] = 'FOREIGN KEY (household_id,recipe_id) REFERENCES recipes(household_id,id) ON DELETE CASCADE';
        }
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
$pdo->exec('CREATE TABLE recipe_relationship_lock(id INTEGER PRIMARY KEY); INSERT INTO recipe_relationship_lock VALUES(1)');
$pdo->exec('CREATE TABLE recipe_households(recipe_id INTEGER,household_id INTEGER,removed_at TEXT,PRIMARY KEY(recipe_id,household_id))');
$checks = 0;
function relationExpect(bool $condition,string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function relationReject(callable $action,int $status,string $code = ''): void {
    try { $action(); }
    catch (HttpError $error) {
        relationExpect($error->status===$status,'Unexpected rejection: '.$error->getMessage());
        if ($code!=='') relationExpect($error->errorCode===$code,'Unexpected error code.');
        return;
    }
    throw new RuntimeException('Invalid variation was accepted.');
}
function relationSnapshot(PDO $pdo,string $id): array {
    $result = [];
    foreach (['recipes','recipe_ingredient_groups','recipe_ingredients','recipe_steps','recipe_step_actions','recipe_photos','recipe_tags','recipe_category_assignments','recipe_nutrition'] as $table) {
        $q = $pdo->prepare('SELECT * FROM '.$table.' WHERE '.($table==='recipes' ? 'id' : 'recipe_id').'=?');
        $q->execute([$id]); $result[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
$households = new HouseholdService($pdo);
$read = new RecipeService($pdo,$households);
$editor = new RecipeEditorService($pdo,$households,'unused','https://example.test/media');
$base = ['title'=>'Original soup','description'=>'Original description','original_language'=>'en','measurement_system'=>'metric',
    'ingredients'=>[['id'=>'new-i','original_text'=>'1 litre water','ingredient_name'=>'water','quantity_decimal'=>'1','unit_code'=>'l','scale_quantity'=>true]],
    'steps'=>[['id'=>'new-s','instruction'=>'Simmer the water.']],
    'actions'=>[['id'=>'new-a','step_id'=>'new-s','action_type'=>'timer','duration_seconds'=>60]],
    'groups'=>[], 'photos'=>[], 'tags'=>[], 'category_assignments'=>[], 'pairings'=>[]];

require_once dirname(__DIR__).'/src/RecipeShare.php';
$pdo->exec("CREATE TABLE household_people (id INTEGER PRIMARY KEY,household_id INTEGER,user_id INTEGER,display_name TEXT);
CREATE TABLE IF NOT EXISTS recipe_favorites (household_id INTEGER,recipe_id INTEGER,person_id INTEGER,PRIMARY KEY(household_id,recipe_id,person_id));
INSERT INTO household_people VALUES (1,1,1,'Alice');
INSERT INTO recipe_categories (id,label,slug,position) VALUES (1,'Desserts','desserts',1);");
$blank=$base; $blank['source_type']='blank'; $blank['title']='Birthday cake';
$blank['ingredients']=[]; $blank['steps']=[]; $blank['actions']=[];
relationReject(fn()=>$editor->save('1','1','',$blank),422);
$blank['category_assignments']=[['category_id'=>'1']];
$blank['favorite_on_create']=true;
$saved=$editor->save('1','1','',$blank);
relationExpect($saved['source_type']==='blank' && $saved['ingredients']===[] && $saved['steps']===[],'Blank state lost.');
relationExpect((int)$pdo->query('SELECT COUNT(*) FROM recipe_favorites')->fetchColumn()===1,'Favorite not created.');
relationExpect(count($read->browse('1','1','Birthday')['recipes'])===1,'Blank absent from search.');
relationExpect(count($read->browse('1','1','',0,'','1')['recipes'])===1,'Blank absent from category browse.');
try { RecipeShare::load($pdo,$saved['id']); throw new RuntimeException('Blank shared publicly.'); }
catch (HttpError $e) { relationExpect($e->status===404,'Blank share status.'); }
$bad=$saved; $bad['title']=''; relationReject(fn()=>$editor->save('1','1',$saved['id'],$bad),422);
$bad=$saved; $bad['category_assignments']=[]; relationReject(fn()=>$editor->save('1','1',$saved['id'],$bad),422);
$bad=$saved; $bad['ingredients']=$base['ingredients']; relationReject(fn()=>$editor->save('1','1',$saved['id'],$bad),422);
relationReject(fn()=>$editor->save('2','1',$saved['id'],$saved),404);
// Seed an existing uploaded photo to exercise the normal ownership-preserving edit path.
$pdo->exec("INSERT INTO recipe_photos (id,household_id,recipe_id,position,source_url,storage_key,mime_type,width_pixels,height_pixels,is_cover) VALUES (1,1,".(int)$saved['id'].",1,'https://example.test/cake.png','photo-key','image/png',1,1,1)");
$saved=$read->detail('1','1',$saved['id']);
$updated=$saved; $updated['title']='Wedding cake';
$updated=$editor->save('1','1',$saved['id'],$updated);
relationExpect($updated['source_type']==='blank','Edit changed blank marker.');
relationReject(fn()=>$editor->save('1','1',$saved['id'],$saved),409);
$import=$updated; $import['source_type']='ai_text'; $import['ingredients']=$base['ingredients']; $import['steps']=$base['steps'];
$converted=$editor->save('1','1',$saved['id'],$import);
relationExpect(count($converted['photos'])===1 && $converted['photos'][0]['id']==='1','Import lost existing photo.');
relationExpect($converted['id']===$saved['id'] && $converted['source_type']==='ai_text' && count($converted['ingredients'])===1,'Import failed to fill original blank.');
relationExpect((int)$pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===1,'Import created duplicate.');
relationExpect((int)$pdo->query('SELECT COUNT(*) FROM recipe_favorites')->fetchColumn()===1,'Import lost favorite.');
relationExpect(RecipeShare::load($pdo,$saved['id'])['title']==='Wedding cake','Imported recipe cannot share.');
echo "Blank recipes: $checks checks passed.\n";
