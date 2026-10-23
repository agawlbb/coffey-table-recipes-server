<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
// CI-only, disposable MySQL fixture. Never reads the hosted/local configuration.
$dsn=getenv('CTR_MEAL_TEST_DSN');
if ($dsn!=='mysql:host=127.0.0.1;port=3306;charset=utf8mb4') throw new RuntimeException('Explicit isolated CI database required');
$pdo=new PDO($dsn,'root',getenv('CTR_MEAL_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='ctr_meal_fixture_'.bin2hex(random_bytes(8));
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE `$name`");
    $pdo->exec('CREATE TABLE households(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE household_memberships(household_id BIGINT UNSIGNED,user_id BIGINT UNSIGNED,role VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE recipes(id BIGINT UNSIGNED PRIMARY KEY,title VARCHAR(300),revision BIGINT UNSIGNED,deleted_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE recipe_households(recipe_id BIGINT UNSIGNED,household_id BIGINT UNSIGNED,removed_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE recipe_photos(id BIGINT UNSIGNED PRIMARY KEY,recipe_id BIGINT UNSIGNED,source_url TEXT,storage_key TEXT,is_cover BOOLEAN,position INT) ENGINE=InnoDB');
    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/016_meal_plans.sql');
    foreach (explode(';',$sql) as $statement) if(trim($statement)!=='')$pdo->exec($statement);
    // An interrupted migration can safely repeat its additive DDL.
    foreach (explode(';',$sql) as $statement) if(trim($statement)!=='')$pdo->exec($statement);
    $pdo->exec("INSERT INTO households VALUES(1); INSERT INTO household_memberships VALUES(1,1,'owner'); INSERT INTO recipes VALUES(1,'Soup',1,NULL); INSERT INTO recipe_households VALUES(1,1,NULL)");
    $service=new MealPlanService($pdo,new HouseholdService($pdo),clock:fn()=>new DateTimeImmutable('2026-10-22T12:00:00Z'));
    $service->change('1','1',['operation'=>'add','id'=>'fixture','revision'=>0,'recipe_id'=>'1','date'=>'2026-10-21','confirm_past'=>true]);
    $state=$service->read('1','1','2026-10-01','2026-10-31');
    if($state['revision']!==1 || count($state['entries'])!==1)throw new RuntimeException('MySQL plan write/read failed');
    try {$service->change('1','1',['operation'=>'remove','id'=>'fixture','revision'=>0,'confirm_past'=>true]);throw new RuntimeException('Stale revision accepted');}
    catch(HttpError $error){if($error->status!==409)throw $error;}
    $pdo->exec('DELETE FROM recipes WHERE id=1');
    $state=$service->read('1','1','2026-10-01','2026-10-31');
    if($state['entries'][0]['recipe_id']!==null || $state['entries'][0]['recipe_identity']!=='1' || $state['entries'][0]['title']!=='Soup')throw new RuntimeException('MySQL history snapshot lost on purge');
    echo "Isolated MySQL migration, repeatability, CAS and deletion retention passed.\n";
} finally {
    if(!preg_match('/^ctr_meal_fixture_[a-f0-9]{16}$/D',$name))throw new RuntimeException('Unexpected fixture name');
    $pdo->exec("DROP DATABASE `$name`");
}
