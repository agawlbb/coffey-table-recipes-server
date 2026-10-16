<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$checks = 0;
function expectSchema(bool $value, string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function schemaReject(PDO $pdo, string $sql): void {
    try { $pdo->exec($sql); }
    catch (PDOException $error) {
        expectSchema(in_array($error->getCode(), ['23000', 'HY000'], true), 'Unexpected constraint error.');
        return;
    }
    throw new RuntimeException('Invalid data accepted: ' . $sql);
}

$config = configuration();
if ($config['environment'] !== 'development') throw new RuntimeException('Run only in development.');
$db = $config['database'];
$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$testDatabase = 'coffey_recipe_test_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $config['database']['name'] = $testDatabase;
    $pdo = database($config);
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        foreach (explode(';', file_get_contents($file)) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
    }
    $households = new HouseholdService($pdo);
    $a = $households->bootstrap(['uid' => 'recipe-test-a', 'name' => 'Alice']);
    $b = $households->bootstrap(['uid' => 'recipe-test-b', 'name' => 'Bob']);
    $h1 = $households->create($a['id'], 'A', 'Alice')['id'];
    $h2 = $households->create($b['id'], 'B', 'Bob')['id'];
    $person = $households->people($a['id'], $h1)[0]['id'];
    $foreignPerson = $households->people($b['id'], $h2)[0]['id'];
    $insert = $pdo->prepare('INSERT INTO recipes (household_id, title, original_language, source_text, import_key) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$h1, 'Crêpes', 'fr', '250 g de farine', 'test-crepes']); $r1 = $pdo->lastInsertId();
    $insert->execute([$h1, 'Crêpes variation', 'fr', null, null]); $r2 = $pdo->lastInsertId();
    $insert->execute([$h2, 'Foreign recipe', 'en', null, 'test-crepes']); $foreign = $pdo->lastInsertId();
    expectSchema($pdo->query("SELECT source_text FROM recipes WHERE id=$r1")->fetchColumn() === '250 g de farine', 'Original text lost.');
    schemaReject($pdo, "INSERT INTO recipes (household_id,title,import_key) VALUES ($h1,'Duplicate','test-crepes')");
    schemaReject($pdo, "INSERT INTO recipes (household_id,title,created_by_user_id) VALUES ($h1,'Foreign author',{$b['id']})");
    $pdo->exec("UPDATE recipes SET parent_recipe_id=$foreign WHERE id=$r2");
    expectSchema($pdo->query("SELECT parent_recipe_id FROM recipes WHERE id=$r2")->fetchColumn() == $foreign, 'Canonical foreign-storage parent rejected.');
    $pdo->exec("UPDATE recipes SET parent_recipe_id=$r1 WHERE id=$r2");
    expectSchema($pdo->query("SELECT parent_recipe_id FROM recipes WHERE id=$r2")->fetchColumn() == $r1, 'Variation link failed.');
    $pdo->exec("INSERT INTO recipe_ingredient_groups (household_id,recipe_id,position,title) VALUES ($h1,$r1,1,'Batter')");
    $group = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO recipe_ingredients (household_id,recipe_id,group_id,position,original_text,ingredient_name,quantity_decimal,original_unit,unit_code) VALUES ($h1,$r1,$group,1,'250 g de farine','farine',250,'g','g')");
    $pdo->exec("INSERT INTO recipe_ingredients (household_id,recipe_id,position,original_text,ingredient_name,quantity_numerator,quantity_denominator,original_unit) VALUES ($h1,$r1,2,'1/3 cup milk','milk',1,3,'cup')");
    expectSchema($pdo->query("SELECT quantity_decimal FROM recipe_ingredients WHERE recipe_id=$r1 AND position=1")->fetchColumn() === '250.00000000', 'Decimal quantity lost.');
    $fraction = $pdo->query("SELECT quantity_numerator,quantity_denominator FROM recipe_ingredients WHERE recipe_id=$r1 AND position=2")->fetch(PDO::FETCH_ASSOC);
    expectSchema($fraction['quantity_numerator'] == 1 && $fraction['quantity_denominator'] == 3, 'Exact fraction lost.');
    schemaReject($pdo, "INSERT INTO recipe_ingredients (household_id,recipe_id,group_id,position,original_text,ingredient_name) VALUES ($h1,$r2,$group,1,'wrong','wrong')");
    schemaReject($pdo, "INSERT INTO recipe_ingredients (household_id,recipe_id,position,original_text,ingredient_name,quantity_numerator,quantity_denominator) VALUES ($h1,$r1,3,'bad','bad',1,0)");
    schemaReject($pdo, "INSERT INTO recipe_ingredients (household_id,recipe_id,position,original_text,ingredient_name,quantity_decimal,quantity_numerator,quantity_denominator) VALUES ($h1,$r1,3,'bad','bad',0.5,1,2)");
    $pdo->exec("INSERT INTO recipe_steps (household_id,recipe_id,position,instruction) VALUES ($h1,$r1,1,'Préchauffer à 180°C.')");
    $step = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO recipe_step_actions (household_id,recipe_id,step_id,position,action_type,temperature_value,temperature_unit) VALUES ($h1,$r1,$step,1,'appliance_temperature',180,'C')");
    expectSchema($pdo->query("SELECT temperature_value FROM recipe_step_actions WHERE step_id=$step")->fetchColumn() === '180.0000', 'Original temperature lost.');
    schemaReject($pdo, "INSERT INTO recipe_step_actions (household_id,recipe_id,step_id,position,action_type,duration_seconds) VALUES ($h1,$r2,$step,2,'timer',60)");
    schemaReject($pdo, "INSERT INTO recipe_step_actions (household_id,recipe_id,step_id,position,action_type) VALUES ($h1,$r1,$step,2,'timer')");
    $pdo->exec("INSERT INTO recipe_photos (household_id,recipe_id,position,storage_key,is_cover) VALUES ($h1,$r1,1,'households/$h1/recipes/$r1/cover.jpg',1)");
    schemaReject($pdo, "INSERT INTO recipe_photos (household_id,recipe_id,position,storage_key,is_cover) VALUES ($h1,$r1,2,'second.jpg',1)");
    $pdo->exec("INSERT INTO recipe_photos (household_id,recipe_id,position,source_url) VALUES ($h1,$r1,2,'https://example.invalid/photo.jpg')");
    expectSchema($pdo->query("SELECT COUNT(*) FROM recipe_photos WHERE recipe_id=$r1")->fetchColumn() == 2, 'Multiple photos rejected.');
    $pdo->exec("INSERT INTO recipe_pairings (household_id,recipe_id,paired_recipe_id) VALUES ($h1,$r1,$foreign)");
    expectSchema($pdo->query("SELECT COUNT(*) FROM recipe_pairings WHERE recipe_id=$r1 AND paired_recipe_id=$foreign")->fetchColumn()==1,'Cross-storage pairing rejected.');
    $pdo->exec("DELETE FROM recipe_pairings WHERE recipe_id=$r1 AND paired_recipe_id=$foreign");
    $pdo->exec("INSERT INTO recipe_pairings (household_id,recipe_id,paired_recipe_id) VALUES ($h1,$r1,$r2)");
    schemaReject($pdo, "INSERT INTO recipe_pairings (household_id,recipe_id,paired_recipe_id) VALUES ($h1,$r2,$r1)");
    schemaReject($pdo, "INSERT INTO recipe_favorites (household_id,recipe_id,person_id) VALUES ($h1,$r1,$foreignPerson)");
    $pdo->exec("INSERT INTO recipe_favorites (household_id,recipe_id,person_id) VALUES ($h1,$r1,$person)");
    $pdo->exec("INSERT INTO recipe_nutrition (household_id,recipe_id,recipe_revision,method,calories_kcal) VALUES ($h1,$r1,1,'ingredient calculation',150)");
    $pdo->exec("UPDATE recipes SET revision=revision+1 WHERE id=$r1");
    expectSchema($pdo->query("SELECT r.revision <> n.recipe_revision FROM recipes r JOIN recipe_nutrition n ON n.recipe_id=r.id WHERE r.id=$r1")->fetchColumn() == 1, 'Stale nutrition cannot be detected.');
    $pdo->exec("INSERT INTO cooking_events (household_id,cooked_at) VALUES ($h1,'2026-10-08 12:00:00')");
    $event = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO cooking_event_recipes (household_id,event_id,recipe_id,recipe_revision) VALUES ($h1,$event,$r1,2),($h1,$event,$r2,1)");
    expectSchema($pdo->query("SELECT COUNT(*) FROM cooking_event_recipes WHERE event_id=$event")->fetchColumn() == 2, 'Meal history cannot group recipes.');
    schemaReject($pdo, "INSERT INTO cooking_event_recipes (household_id,event_id,recipe_id,recipe_revision) VALUES ($h1,$event,$foreign,1)");
    $pdo->exec("DELETE FROM cooking_events WHERE id=$event");
    $pdo->exec('INSERT INTO recipe_households(recipe_id,household_id) SELECT id,household_id FROM recipes');
    $service = new RecipeService($pdo, $households);
    $breakfast = (string) $pdo->query("SELECT id FROM recipe_categories WHERE slug='breakfast'")->fetchColumn();
    $pdo->exec("INSERT INTO recipe_categories(parent_id,slug,label,image_key) VALUES ($breakfast,'breakfast-pancakes','Pancakes','pancakes')");
    $pancakes = (string) $pdo->query("SELECT id FROM recipe_categories WHERE slug='breakfast-pancakes'")->fetchColumn();
    $muffins = (string) $pdo->query("SELECT id FROM recipe_categories WHERE slug='breakfast-muffins'")->fetchColumn();
    $pdo->exec("INSERT INTO recipe_category_assignments VALUES ($h1,$r1,$pancakes),($h1,$r1,$muffins),($h2,$foreign,$muffins)");
    schemaReject($pdo, "INSERT INTO recipe_category_assignments VALUES ($h1,$foreign,$pancakes)");
    $roots = $service->browse($a['id'], $h1, '', 0, '', '', true);
    expectSchema(count($roots['category_nodes']) === 6 && $roots['view'] === 'categories', 'Root categories missing.');
    $rootBreakfast = array_values(array_filter($roots['category_nodes'], static fn($n) => $n['id'] === $breakfast))[0];
    expectSchema((int)$rootBreakfast['recipe_count'] === 1, 'Root count double-counts multi-category recipes.');
    expectSchema(count($service->browse($a['id'], $h1, '', 0, '', $breakfast, true)['category_nodes']) === 2, 'Present subcategories missing.');
    expectSchema(count($service->browse($b['id'], $h2, '', 0, '', $breakfast, true)['category_nodes']) === 1, 'Subcategories leak other households.');
    expectSchema(count($service->browse($a['id'], $h1, 'farine', 0, '', $breakfast, true)['recipes']) === 1, 'Root scoped search failed.');
    expectSchema(count($service->browse($a['id'], $h1, 'Muffins', 0, '', $breakfast, true)['recipes']) === 1, 'Category label search failed.');
    expectSchema(count($service->browse($a['id'], $h1, '', 0, '', $pancakes, true)['recipes']) === 1, 'Leaf browsing failed.');
    expectSchema(count($service->browse($a['id'], $h1, 'Foreign', 0, '', $muffins, true)['recipes']) === 0, 'Scoped search leaks other households.');
    expectSchema(count($service->browse($a['id'], $h1, '%', 0, '', $breakfast, true)['recipes']) === 0, 'Scoped wildcard search was not escaped.');
    foreach (['0', '1 OR 1=1', '999999'] as $scope) {
        try { $service->browse($a['id'], $h1, '', 0, '', $scope, true); throw new RuntimeException('Invalid scope accepted.'); }
        catch (HttpError $error) { expectSchema(in_array($error->status, [400,404], true), 'Wrong scope rejection response.'); }
    }
    $pdo->exec("INSERT INTO recipe_tags (household_id,recipe_id,kind,label) VALUES ($h1,$r1,'category','Breakfast'),($h2,$foreign,'category','Foreign category')");
    $filtered = $service->browse($a['id'], $h1, 'farine', 0, 'Breakfast');
    expectSchema(count($filtered['recipes']) === 1 && $filtered['categories'] === ['Breakfast'], 'Household category filtering failed.');
    expectSchema(count($service->browse($a['id'], $h1, '', 0, 'Foreign category')['recipes']) === 0, 'Foreign category disclosed.');
    expectSchema(count($service->browse($a['id'], $h1)['recipes']) === 2, 'Browse leaks another household.');
    expectSchema(count($service->browse($a['id'], $h1, 'farine')['recipes']) === 1, 'Ingredient search failed.');
    expectSchema(count($service->browse($a['id'], $h1, '%')['recipes']) === 0, 'Search wildcard was not escaped.');
    $detail = $service->detail($a['id'], $h1, $r1);
    expectSchema($detail['id'] === (string) $r1 && count($detail['ingredients']) === 2 && count($detail['steps']) === 1, 'Detail response incomplete.');
    expectSchema($detail['nutrition']['stale'] === true && count($detail['related']) === 1 && count($detail['pairings']) === 1, 'Related data missing.');
    expectSchema(!array_key_exists('source_text', $detail), 'Raw extraction exposed.');
    foreach ([static fn() => $service->browse($a['id'], $h2), static fn() => $service->detail($a['id'], $h1, $foreign)] as $request) {
        try { $request(); throw new RuntimeException('Foreign recipe disclosed.'); }
        catch (HttpError $error) { expectSchema($error->status === 404, 'Wrong isolation response.'); }
    }
    $pdo->exec("UPDATE recipes SET parent_recipe_id=NULL WHERE id=$r2");
    for ($i=0; $i<31; $i++) {
        $insert->execute([$h1, sprintf('Paging %02d', $i), 'en', null, null]);
        $pagingId = $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO recipe_households(recipe_id,household_id) VALUES (?,?)')->execute([$pagingId,$h1]);
        $pdo->exec("INSERT INTO recipe_category_assignments VALUES ($h1,$pagingId,$pancakes)");
    }
    $page = $service->browse($a['id'], $h1, 'Paging', 0, '', $pancakes, true);
    expectSchema(count($page['recipes']) === 30 && $page['next_offset'] === 30, 'Scoped paging first page failed.');
    $lastPage = $service->browse($a['id'], $h1, 'Paging', 30, '', $pancakes, true);
    expectSchema(count($lastPage['recipes']) === 1 && $lastPage['next_offset'] === null, 'Scoped paging final page failed.');
    $pdo->exec("DELETE FROM recipes WHERE id=$r1");
    foreach (['recipe_ingredient_groups', 'recipe_ingredients', 'recipe_steps', 'recipe_step_actions', 'recipe_photos', 'recipe_tags', 'recipe_favorites', 'recipe_nutrition', 'recipe_category_assignments'] as $table) {
        expectSchema($pdo->query("SELECT COUNT(*) FROM $table WHERE recipe_id=$r1")->fetchColumn() == 0, "Recipe children remain in $table.");
    }
} finally {
    if (!preg_match('/^coffey_recipe_test_[a-f0-9]{10}$/', $testDatabase)) throw new RuntimeException('Invalid test database name.');
    $admin->exec("DROP DATABASE `$testDatabase`");
}
echo "$checks recipe schema checks passed.\n";
