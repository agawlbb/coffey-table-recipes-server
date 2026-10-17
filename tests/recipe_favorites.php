<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
ob_start();
final class FavoriteDatabase extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {
  return parent::prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$query),$options);
 }
}
$pdo=new FavoriteDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE household_people (id INTEGER,household_id INTEGER,user_id INTEGER,display_name TEXT);
CREATE TABLE recipes (id INTEGER,deleted_at TEXT);
CREATE TABLE recipe_households (recipe_id INTEGER,household_id INTEGER,removed_at TEXT);
CREATE TABLE recipe_favorites (household_id INTEGER,recipe_id INTEGER,person_id INTEGER,PRIMARY KEY(household_id,recipe_id,person_id));
INSERT INTO household_memberships VALUES (1,1,'member'),(2,2,'owner');
INSERT INTO household_people VALUES (10,1,1,'Joe'),(11,1,NULL,'Brooklyn'),(20,2,2,'Other');
INSERT INTO recipes VALUES (5,NULL),(6,'deleted');
INSERT INTO recipe_households VALUES (5,1,NULL),(5,2,NULL),(6,1,NULL);");
$s=new RecipeService($pdo,new HouseholdService($pdo));
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Favorite assertion failed'); }
check($s->favorites('1','1','5')['current_person_id']==='10');
$s->setFavorite('1','1','5',['favorite'=>true]);
$s->setFavorite('1','1','5',['favorite'=>true]);
$s->setFavorite('1','1','5',['person_id'=>'11','favorite'=>true]);
check((int)$pdo->query('SELECT COUNT(*) FROM recipe_favorites')->fetchColumn()===2);
check(array_sum(array_column($s->favorites('2','2','5')['people'],'favorite'))===0);
$s->setFavorite('1','1','5',['person_id'=>'11','favorite'=>false]);
check((int)$pdo->query('SELECT COUNT(*) FROM recipe_favorites')->fetchColumn()===1);
foreach ([fn()=>$s->favorites('2','1','5'),fn()=>$s->favorites('1','1','6'),fn()=>$s->setFavorite('1','1','5',['person_id'=>'20','favorite'=>true]),fn()=>$s->setFavorite('1','1','5',['favorite'=>'true'])] as $invalid) {
 try { $invalid(); throw new RuntimeException('Expected rejection'); } catch (HttpError $expected) {}
}
$pdo->exec("ALTER TABLE recipes ADD COLUMN title TEXT;
ALTER TABLE recipes ADD COLUMN parent_recipe_id INTEGER;
ALTER TABLE recipes ADD COLUMN household_id INTEGER;
ALTER TABLE recipes ADD COLUMN description TEXT;
ALTER TABLE recipes ADD COLUMN original_language TEXT;
ALTER TABLE recipes ADD COLUMN servings TEXT;
ALTER TABLE recipes ADD COLUMN yield_text TEXT;
ALTER TABLE recipes ADD COLUMN prep_minutes INTEGER;
ALTER TABLE recipes ADD COLUMN cook_minutes INTEGER;
ALTER TABLE recipes ADD COLUMN total_minutes INTEGER;
CREATE TABLE recipe_photos (recipe_id INTEGER,source_url TEXT,storage_key TEXT,is_cover INTEGER,position INTEGER);
CREATE TABLE recipe_tags (recipe_id INTEGER,household_id INTEGER,kind TEXT,label TEXT);
CREATE TABLE household_invitations (person_id INTEGER,status TEXT,expires_at TEXT,email TEXT);
UPDATE recipes SET title='Soup',household_id=1;");
// SQLite stand-in for the MySQL UTC clock used by people().
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn($precision)=>'2026-10-17');
check(count($s->browse('1','1','',0,'','',true,true,false,'10')['recipes'])===1);
check(count($s->browse('1','1','',0,'','',true,true,false,'10,11')['recipes'])===0);
$s->setFavorite('1','1','5',['person_id'=>'11','favorite'=>true]);
check(count($s->browse('1','1','',0,'','',true,true,false,'10,11')['recipes'])===1);
try { $s->browse('1','1','',0,'','',false,false,false,'20'); throw new RuntimeException('Expected filter rejection'); } catch (HttpError $expected) {}
echo "Recipe favorites checks passed\n";

// Exercise the actual route dispatch with the real fixture service. respond()
// returns normally, as in production: success must not fall through to a 405.
$source=file_get_contents(dirname(__DIR__).'/index.php');
$anchor=strpos($source, '$service=new RecipeService($pdo,$households);');
$start=strpos($source, "        if (".'$method'."==='GET')", $anchor);
$end=strpos($source, "    } elseif (preg_match", $start);
$dispatch=str_replace('requestBody()', "['person_id'=>'10','favorite'=>false]", substr($source,$start,$end-$start));
$service=$s; $user=['id'=>'1']; $favoriteMatch=[null,'1','5'];
foreach (['GET','POST','DELETE'] as $method) {
    $before=ob_get_level(); ob_start();
    try {
        eval($dispatch);
        check($method!=='DELETE');
        check(http_response_code()===200);
        $response=json_decode(ob_get_contents(),true,16,JSON_THROW_ON_ERROR);
        check(isset($response['people'],$response['current_person_id']));
        if ($method==='POST') check((int)$pdo->query('SELECT COUNT(*) FROM recipe_favorites WHERE person_id=10')->fetchColumn()===0);
    } catch (HttpError $error) {
        check($method==='DELETE' && $error->status===405);
    } finally { while (ob_get_level()>$before) ob_end_clean(); }
}
echo "Favorites route GET/POST/405 checks passed\n";
ob_end_flush();
