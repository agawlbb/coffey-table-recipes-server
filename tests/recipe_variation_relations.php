<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

// Disposable in-memory fixtures only. No configuration, network or hosted DB.
// SQLite checks behavior and lock SQL ordering, not InnoDB blocking semantics.
final class VariationRelationStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        try { return parent::execute($params); }
        catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'recipes.household_id, recipes.import_key')) $error->errorInfo[1] = 1062;
            throw $error;
        }
    }
}
final class VariationRelationDatabase extends PDO {
    public array $queries = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return parent::prepare(str_replace([' FOR UPDATE',' <=> '],['',' IS '],$query),$options);
    }
}
$pdo = new VariationRelationDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[VariationRelationStatement::class]);
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
            array_push($columns,'deleted_at TEXT','purge_after TEXT','deleted_by_user_id INTEGER','UNIQUE(household_id,id)',
                'UNIQUE(household_id,import_key)','FOREIGN KEY (household_id,parent_recipe_id) REFERENCES recipes(household_id,id)');
        } elseif (!in_array($table[1],['recipe_categories','cooking_events'],true)) {
            $columns[] = 'FOREIGN KEY (household_id,recipe_id) REFERENCES recipes(household_id,id) ON DELETE CASCADE';
        }
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
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
$original = $editor->save('1','1','',$base);
$originalBefore = relationSnapshot($pdo,$original['id']);
$foreign = $editor->save('2','2','',$base);
$variation = $base;
$variation['title']='Ginger soup';
$variation['parent_recipe_id']=$original['id'];
$variation['source_type']='ai_variation';
$variation['source_text']='Make it gingery';
$variation['import_key']=hash('sha256','variation-1');
$variation['is_variation']=false; // A client flag never controls the read marker.
$variation['change_description']='Temporary comparison: added ginger for warmth.';
$countBefore = $pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
relationExpect($countBefore==2,'Draft construction unexpectedly wrote a recipe.');
$pdo->queries=[];
$saved = $editor->save('1','1','',$variation);
$saveQueries=$pdo->queries;
relationExpect($saved['id']!==$original['id'] && $saved['parent_recipe_id']===$original['id'],'Variation was not a separate linked recipe.');
relationExpect($saved['is_variation']===true && $original['is_variation']===false,'Variation marker is not derived from the parent.');
relationExpect($saved['ingredients'][0]['id']!==$original['ingredients'][0]['id'] && $saved['steps'][0]['id']!==$original['steps'][0]['id'],'Variation reused original child IDs.');
relationExpect(relationSnapshot($pdo,$original['id'])===$originalBefore,'Saving a variation changed its original.');
relationExpect(!isset($saved['source_text']) && !isset($saved['import_key']),'Private draft bookkeeping escaped the response.');
relationExpect(!str_contains(json_encode(relationSnapshot($pdo,$saved['id'])),'Temporary comparison') && !isset($saved['change_description']),'Review explanation is not persisted even if supplied by a client');
$locking = array_values(array_filter($saveQueries,fn($sql)=>str_contains($sql,'FOR UPDATE')));
relationExpect($locking[0]==='SELECT id FROM households WHERE id=? FOR UPDATE','Household serialization must precede every recipe lock.');
relationExpect(in_array('SELECT parent_recipe_id,deleted_at FROM recipes WHERE household_id=? AND id=? FOR UPDATE',$locking,true),'Ancestor reads are not locking current reads.');

$savedBefore=relationSnapshot($pdo,$saved['id']);
$retry=$variation; $retry['title']='Do not apply this replay'; $retry['ingredients']=[];
$retried=$editor->save('3','1','',$retry);
relationExpect($retried['id']===$saved['id'] && $retried['revision']===$saved['revision'],'Save retry did not return the existing recipe unchanged.');
relationExpect(relationSnapshot($pdo,$saved['id'])===$savedBefore,'Retry overwrote saved content.');
relationExpect($pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()==3,'Retry created a duplicate recipe.');
relationReject(fn()=>$editor->save('2','1','',$variation),404);
$mismatch=$variation; $mismatch['parent_recipe_id']=$saved['id'];
relationReject(fn()=>$editor->save('1','1','',$mismatch),409);
$ordinary=$variation; $ordinary['source_type']='text';
relationReject(fn()=>$editor->save('1','1','',$ordinary),409);
$ordinary['import_key']=hash('sha256','ordinary-import');
$editor->save('1','1','',$ordinary);
relationReject(fn()=>$editor->save('1','1','',$ordinary),409);
$collision=$variation; $collision['import_key']=$ordinary['import_key'];
relationReject(fn()=>$editor->save('1','1','',$collision),409);
$separate=$variation; $separate['parent_recipe_id']=$foreign['id'];
$otherSaved=$editor->save('2','2','',$separate);
relationExpect($otherSaved['id']!==$saved['id'],'Import keys were not household-scoped.');

$sibling=$variation; $sibling['title']='Pepper soup'; $sibling['import_key']=hash('sha256','variation-sibling');
$siblingSaved=$editor->save('1','1','',$sibling);
$child=$variation; $child['parent_recipe_id']=$saved['id']; $child['title']='Ginger and lime soup'; $child['import_key']=hash('sha256','variation-child');
$childSaved=$editor->save('1','1','',$child);
$grandchild=$child; $grandchild['parent_recipe_id']=$childSaved['id']; $grandchild['import_key']=hash('sha256','variation-grandchild');
$grandchildSaved=$editor->save('1','1','',$grandchild);
$current=$read->detail('1','1',$saved['id']);
$related=array_column($current['related'],null,'id');
relationExpect($related[$original['id']]['relationship']==='parent','Original is not labeled as parent.');
relationExpect($related[$siblingSaved['id']]['relationship']==='sibling','Sibling is missing or mislabeled.');
relationExpect($related[$childSaved['id']]['relationship']==='child','Nested variation does not show its direct child.');
relationExpect(!isset($related[$saved['id']]) && !isset($related[$grandchildSaved['id']]) && !isset($related[$otherSaved['id']]),'Related recipes included self, non-adjacent descendants or another household.');
relationExpect($related[$original['id']]['is_variation']===false && $related[$childSaved['id']]['is_variation']===true,'Related variation markers are incorrect.');
$originalRelated=$read->detail('1','1',$original['id'])['related'];
relationExpect(count(array_filter($originalRelated,fn($row)=>$row['relationship']!=='child'))===0,'An original recipe has spurious sibling links.');
$browse=array_column($read->browse('1','1')['recipes'],null,'id');
relationExpect($browse[$original['id']]['is_variation']===false && $browse[$saved['id']]['is_variation']===true,'Browse markers are incorrect.');
$family=$current['variation_family'];
relationExpect(count($family)===6 && in_array($grandchildSaved['id'],array_column($family,'id'),true),'Family must include nested descendants');
relationExpect($family[0]['id']===$original['id'] && $current['family_representative_id']===$original['id'],'Family representative must be the original');
relationExpect($read->detail('1','1',$grandchildSaved['id'])['variation_family']===$family,'Every family member must expose the same complete family');
relationExpect($read->detail('1','1',$grandchildSaved['id'])['variation_base_title']===$original['title'],'Nested generations must retain the root recipe title');
$grouped=$read->browse('1','1','',0,'','',false,true);
relationExpect(array_column($grouped['recipes'],'id')===[$original['id']],'Grouped browsing must show one family entry');
relationExpect($grouped['next_offset']===null,'Grouped pagination must count families');
relationExpect(array_column($read->browse('1','1','Pepper',0,'','',false,true)['recipes'],'id')===[$original['id']],'Searching a variation must return its family representative');
$pdo->exec("INSERT INTO recipe_categories (id,slug,label,image_key,position) VALUES (200,'soups','Soups','soups',0)");
$q=$pdo->prepare('INSERT INTO recipe_category_assignments (household_id,recipe_id,category_id) VALUES (1,?,200)');
$q->execute([$saved['id']]); $q->execute([$siblingSaved['id']]);
$tree=$read->browse('1','1','',0,'','',true,true);
relationExpect((int)$tree['category_nodes'][0]['recipe_count']===1,'Category counts must count a variation family once');
relationExpect(array_column($read->browse('1','1','',0,'','200',false,true)['recipes'],'id')===[$original['id']],'Variation-only category must resolve to the family representative');
$savedBefore=relationSnapshot($pdo,$saved['id']);

$countBefore=$pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
foreach ([$foreign['id'],'999999',true,0,-1,1.5,[],['id'=>$original['id']],'01','1e0',"1\n","1\r\n",'1 ',null] as $parent) {
    $invalid=$variation; $invalid['parent_recipe_id']=$parent; $invalid['import_key']=hash('sha256','invalid-'.json_encode($parent));
    relationReject(fn()=>$editor->save('1','1','',$invalid),422);
}
$invalid=$variation; unset($invalid['import_key']);
relationReject(fn()=>$editor->save('1','1','',$invalid),422);
$invalid['source_type']=' ai_variation ';
relationReject(fn()=>$editor->save('1','1','',$invalid),422);
$invalid=$variation; $invalid['import_key']=hash('sha256','invalid-key'); $invalid['title']='';
relationReject(fn()=>$editor->save('1','1','',$invalid),422);
relationExpect($pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===$countBefore,'Invalid variation left partial rows.');
$self=$current; $self['parent_recipe_id']=$saved['id'];
relationReject(fn()=>$editor->save('1','1',$saved['id'],$self),422);
$newlineSelf=$current; $newlineSelf['parent_recipe_id']=$saved['id']."\n";
relationReject(fn()=>$editor->save('1','1',$saved['id'],$newlineSelf),422);
relationExpect(relationSnapshot($pdo,$saved['id'])===$savedBefore,'Newline self-parent changed saved recipe.');
$cycle=$read->detail('1','1',$original['id']); $cycle['parent_recipe_id']=$grandchildSaved['id'];
relationReject(fn()=>$editor->save('1','1',$original['id'],$cycle),422);
$newlineCycle=$cycle; $newlineCycle['parent_recipe_id']=$grandchildSaved['id']."\n";
relationReject(fn()=>$editor->save('1','1',$original['id'],$newlineCycle),422);
relationExpect(relationSnapshot($pdo,$original['id'])===$originalBefore,'Failed cycle edit changed original content.');
$badIds=$variation; $badIds['import_key']=hash('sha256','borrowed-children'); $badIds['ingredients']=$original['ingredients'];
relationReject(fn()=>$editor->save('1','1','',$badIds),422);

// Simulate overlapping editors: both capture their drafts before the first
// writes. The second must validate the now-current chain, not its old draft.
$a=$editor->save('1','1','',$base); $b=$editor->save('1','1','',$base);
$a['parent_recipe_id']=$b['id']; $b['parent_recipe_id']=$a['id'];
$editor->save('1','1',$a['id'],$a);
relationReject(fn()=>$editor->save('1','1',$b['id'],$b),422);
relationExpect($read->detail('1','1',$b['id'])['parent_recipe_id']===null,'Overlapping edits formed a cycle.');

// Trash stays invisible, while live descendants can still be edited. Direct
// parent selection must reject trash; cycle walking must still inspect it.
$q=$pdo->prepare("UPDATE recipes SET deleted_at='2026-10-15 00:00:00' WHERE id=?"); $q->execute([$original['id']]);
$hidden=$read->detail('1','1',$saved['id']);
relationExpect(!in_array($original['id'],array_column($hidden['related'],'id'),true),'Deleted parent leaked into related recipes.');
relationExpect(!in_array($original['id'],array_column($hidden['variation_family'],'id'),true) && in_array($grandchildSaved['id'],array_column($hidden['variation_family'],'id'),true),'Deleted ancestors must not hide live descendants or appear in dropdown');
relationExpect(array_values(array_intersect(array_column($read->browse('1','1','',0,'','',false,true)['recipes'],'id'),array_column($family,'id')))===[$saved['id']],'Deleting original must leave one live family representative visible');
relationExpect($hidden['is_variation']===true,'A temporarily deleted original erased the variation marker.');
$invalid=$variation; $invalid['import_key']=hash('sha256','deleted-parent');
relationReject(fn()=>$editor->save('1','1','',$invalid),422);
$nested=$child; $nested['import_key']=hash('sha256','live-parent-deleted-ancestor');
relationExpect($editor->save('1','1','',$nested)['parent_recipe_id']===$saved['id'],'Live immediate parent was rejected solely because a higher ancestor is trashed.');
$q->execute([$siblingSaved['id']]); $q->execute([$childSaved['id']]);
$hidden=$read->detail('1','1',$saved['id']);
relationExpect(!in_array($siblingSaved['id'],array_column($hidden['related'],'id'),true) && !in_array($childSaved['id'],array_column($hidden['related'],'id'),true),'Deleted sibling or child leaked into related recipes.');
$countBefore=$pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
relationReject(fn()=>$editor->save('1','1','',$sibling),409,'recipe_already_imported');
relationExpect($pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===$countBefore,'Retry recreated an intentionally deleted variation.');
$q=$pdo->prepare('SELECT import_key FROM recipes WHERE id=?'); $q->execute([$siblingSaved['id']]);
relationExpect($q->fetchColumn()===$sibling['import_key'],'Rejected replay released the deleted variation key.');
relationExpect($editor->save('1','1','',$variation)['id']===$saved['id'],'Retry did not return a still-live variation after its original was trashed.');

$photoRoot=sys_get_temp_dir().'/variation-photo-save-'.bin2hex(random_bytes(8));
mkdir($photoRoot.'/media/recipes',0700,true); mkdir($photoRoot.'/storage',0700);
try {
    $photoEditor=new RecipeEditorService($pdo,$households,$photoRoot.'/media/recipes','https://example.test/media');
    $image='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNoaGgAAAMEAYFL09IQAAAAAElFTkSuQmCC';
    $photoBase=$base;
    $photoBase['photos']=[['id'=>'new-original-cover','image_base64'=>$image,'is_cover'=>1,'caption'=>'Original cover','attribution'=>'Original photographer']];
    $photoParent=$photoEditor->save('1','1','',$photoBase);
    $parentSnapshot=relationSnapshot($pdo,$photoParent['id']);
    $generation=new RecipeVariationService(['api_key'=>'mock','model'=>'mock','variation_access'=>'all_members'],$photoRoot.'/storage',static function(array $payload): array {
        relationExpect(count(array_filter($payload['input'][0]['content'],fn($part)=>$part['type']==='input_image'))===1,'Real local cover bytes must reach the provider request');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode([
            'recipe'=>['title'=>'Mild original soup','ingredients'=>[['original_text'=>'1 litre water']],'steps'=>[['instruction'=>'Simmer.']]],'questions'=>[],
            'variation_label'=>'chickpeas','change_description'=>'Milder seasoning; the cover still fits and is reused.','parent_cover_appropriate'=>true,'parent_cover_reason'=>'Appearance is unchanged.',
        ])]]]]])];
    });
    $draft=$generation->analyze($photoParent,['changes'=>'Make it mild','request_id'=>str_repeat('4',32)],'1','1');
    $photoVariation=$photoEditor->save('1','1','',array_replace($base,$draft['recipe']));
    relationExpect($photoVariation['photos'][0]['id']!==$photoParent['photos'][0]['id'] && $photoVariation['photos'][0]['storage_key']!==$photoParent['photos'][0]['storage_key'],'Cover reuse must create an independent photo record and file');
    $parentPath=$photoRoot.'/'.$photoParent['photos'][0]['storage_key'];
    $variationPath=$photoRoot.'/'.$photoVariation['photos'][0]['storage_key'];
    relationExpect(file_get_contents($parentPath)===file_get_contents($variationPath),'Reused cover pixels must match the original');
    relationExpect($photoVariation['photos'][0]['attribution']==='Original photographer','Saved reused cover must preserve attribution');
    relationExpect(relationSnapshot($pdo,$photoParent['id'])===$parentSnapshot,'Saving reused cover must not modify original recipe or photo');
    relationExpect(!isset($photoVariation['parent_cover_review']),'Cover assessment is not persisted in the recipe');
} finally {
    foreach (glob($photoRoot.'/media/recipes/*') ?: [] as $file) unlink($file);
    foreach (glob($photoRoot.'/storage/variation-drafts/*') ?: [] as $file) unlink($file);
    if (is_dir($photoRoot.'/storage/variation-drafts')) rmdir($photoRoot.'/storage/variation-drafts');
    rmdir($photoRoot.'/storage'); rmdir($photoRoot.'/media/recipes'); rmdir($photoRoot.'/media'); rmdir($photoRoot);
}
echo "$checks recipe variation relationship fixture checks passed.\n";
