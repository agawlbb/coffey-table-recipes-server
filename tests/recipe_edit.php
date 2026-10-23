<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$checks = 0;
function editExpect(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function editReject(callable $action, int $status): void {
    try { $action(); } catch (HttpError $error) { editExpect($error->status === $status, 'Unexpected rejection: '.$error->getMessage()); return; }
    throw new RuntimeException('Invalid edit was accepted.');
}
$config = configuration();
if (getenv('CTR_RECIPE_TEST_PASSWORD') !== false) {
    $config['environment']='development';
    $config['database']=['host'=>'127.0.0.1','port'=>3306,'user'=>'root','password'=>getenv('CTR_RECIPE_TEST_PASSWORD')];
}

if ($config['environment'] !== 'development') throw new RuntimeException('Use a disposable development database only.');
$db = $config['database'];
$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$db['host'],$db['port']),$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name = 'coffey_edit_test_'.bin2hex(random_bytes(5));
$media = sys_get_temp_dir().'/'.$name;
$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $config['database']['name'] = $name; $pdo = database($config);
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) foreach (explode(';',file_get_contents($file)) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
    $households = new HouseholdService($pdo);
    $a = $households->bootstrap(['uid'=>'edit-a','name'=>'Alice']); $b = $households->bootstrap(['uid'=>'edit-b','name'=>'Bob']);
    $h = $households->create($a['id'],'A','Alice')['id']; $other = $households->create($b['id'],'B','Bob')['id'];
    $q = $pdo->prepare('INSERT INTO recipes (household_id,title,source_text,import_key) VALUES (?,?,?,?)');
    $q->execute([$h,'Original','Private extraction','private-key']); $id = $pdo->lastInsertId();
    $q->execute([$h,'Pair',null,null]); $pair = $pdo->lastInsertId();
    $q->execute([$other,'Foreign',null,null]); $foreign = $pdo->lastInsertId();
    $pdo->exec('INSERT INTO recipe_households(recipe_id,household_id) SELECT id,household_id FROM recipes');
    $read = new RecipeService($pdo,$households); $edit = new RecipeEditorService($pdo,$households,$media,'https://example.test/media/recipes');
    $new = ['title'=>'Imported soup','original_language'=>'en','measurement_system'=>'unspecified','source_text'=>'My soup description','import_key'=>hash('sha256','test-import'),
        'ingredients'=>[['id'=>'new-i','original_text'=>'Water','ingredient_name'=>'water','scale_quantity'=>1]],
        'steps'=>[['id'=>'new-s','instruction'=>'Boil the water.']], 'groups'=>[], 'actions'=>[], 'photos'=>[], 'tags'=>[], 'category_assignments'=>[], 'pairings'=>[]];
    $retry = $new; $retry['import_key']=null; $retry['client_save_id']=str_repeat('r',32);
    $once = $edit->save($a['id'],$h,'',$retry);
    $count = $pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
    editExpect($edit->save($a['id'],$h,'',$retry)===$once,'Lost creation response was not replayed exactly.');
    editExpect($pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===$count,'Retry duplicated recipe.');
    $change=$once; $change['title']='Later edit'; $change['client_save_id']=str_repeat('s',32);
    $changed=$edit->save($a['id'],$h,$once['id'],$change);
    editExpect($edit->save($a['id'],$h,$once['id'],$change)===$changed,'Lost edit response caused revision conflict.');
    editExpect($edit->save($a['id'],$h,'',$retry)===$once,'Retry receipt changed after later edit.');
    $reuse=$retry; $reuse['title']='Wrong body'; editReject(fn()=>$edit->save($a['id'],$h,'',$reuse),409);
    editReject(fn()=>$edit->save($b['id'],$h,'',$retry),404);
    $invalid=$retry; $invalid['client_save_id']=str_repeat('t',32); $invalid['title']='';
    editReject(fn()=>$edit->save($a['id'],$h,'',$invalid),422);
    editExpect($pdo->query("SELECT COUNT(*) FROM recipe_save_receipts WHERE save_id='".str_repeat('t',32)."'")->fetchColumn()==0,'Invalid save left a receipt.');
    $created = $edit->save($a['id'],$h,'',$new);
    editExpect(count($read->editOptions($a['id'],$h)['categories'])>0,'New drafts lack category options.');
    editExpect($created['title']==='Imported soup' && count($created['ingredients'])===1 && count($created['steps'])===1,'New import did not save its content.');
    editExpect($pdo->query('SELECT source_text FROM recipes WHERE id='.(int)$created['id'])->fetchColumn()==='My soup description','Import provenance lost.');
    editReject(fn()=>$edit->save($a['id'],$h,'',$new),409);
    $before = $pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
    $badNew = $new; $badNew['import_key']=hash('sha256','bad-import'); $badNew['title']='';
    editReject(fn()=>$edit->save($a['id'],$h,'',$badNew),422);
    editExpect($pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===$before,'Invalid import left a partial recipe.');
    editReject(fn()=>$edit->save($b['id'],$h,'',$new),404);
    $heat=$new; $heat['import_key']=hash('sha256','qualitative-heat-import');
    $heat['actions']=[['id'=>'new-heat','step_id'=>'new-s','action_type'=>'appliance_temperature','label'=>'Simmer over low heat','temperature_value'=>null,'temperature_unit'=>'F']];
    $heatSaved=$edit->save($a['id'],$h,'',$heat);
    editExpect($heatSaved['actions'][0]['action_type']==='reminder' && $heatSaved['actions'][0]['temperature_value']===null && $heatSaved['actions'][0]['temperature_unit']===null && $heatSaved['actions'][0]['label']==='Simmer over low heat','Qualitative heat failed to save as a reminder.');
    $fraction=$new; $fraction['import_key']=hash('sha256','normalized-fraction');
    $fraction['ingredients'][0]['quantity_numerator']=1; $fraction['ingredients'][0]['quantity_denominator']=2; $fraction['ingredients'][0]['quantity_decimal']=null;
    editExpect($edit->save($a['id'],$h,'',$fraction)['ingredients'][0]['quantity_denominator']==2,'Canonical imported fraction failed to save.');
    $invalidFraction=$fraction; $invalidFraction['import_key']=hash('sha256','invalid-fraction'); $invalidFraction['ingredients'][0]['quantity_decimal']='0.5';
    try { $edit->save($a['id'],$h,'',$invalidFraction); throw new RuntimeException('Conflicting quantity accepted.'); }
    catch (HttpError $error) { editExpect($error->status===422 && str_contains($error->getMessage(),'Ingredient 1 (Water)'), 'Quantity error did not identify its ingredient.'); }
    $cookie = $new; $cookie['import_key']=hash('sha256','cookie-import');
    $cookie['category_assignments']=[['label'=>'Cookies','parent_label'=>'Desserts']];
    $cookie['actions']=[['id'=>'new-timer','step_id'=>'new-s','action_type'=>'timer','label'=>'Simmer','duration_seconds'=>120]];
    $cookieSaved=$edit->save($a['id'],$h,'',$cookie);
    $cookieCategory=$cookieSaved['category_assignments'][0]['category_id'];
    editExpect($cookieSaved['actions'][0]['duration_seconds']==120 && $cookieSaved['actions'][0]['step_id']===$cookieSaved['steps'][0]['id'],'Imported timer not saved on its step.');
    $cookie['import_key']=hash('sha256','second-cookie-import');
    $cookieAgain=$edit->save($a['id'],$h,'',$cookie);
    editExpect($cookieAgain['category_assignments'][0]['category_id']===$cookieCategory,'Existing category was not reused.');
    editExpect($pdo->query("SELECT COUNT(*) FROM recipe_categories WHERE label='Cookies'")->fetchColumn()==1,'Duplicate category created.');
    $badCookie=$cookie; $badCookie['import_key']=hash('sha256','bad-cookie-import');
    $badCookie['category_assignments']=[['label'=>'Temporary','parent_label'=>'Desserts'],['label'=>'Invalid','parent_label'=>'Missing parent']];
    editReject(fn()=>$edit->save($a['id'],$h,'',$badCookie),422);
    editExpect($pdo->query("SELECT COUNT(*) FROM recipe_categories WHERE label='Temporary'")->fetchColumn()==0,'Failed save left a new category.');
    $draft = $read->detail($a['id'],$h,$id);
    $draft['title']='Edited'; $draft['servings']='4'; $draft['notes']='Useful notes';
    $draft['groups']=[['id'=>'new-g','title'=>'Sauce']];
    $draft['ingredients']=[['id'=>'new-i','group_id'=>'new-g','original_text'=>'1/2 cup milk','ingredient_name'=>'milk','quantity_numerator'=>1,'quantity_denominator'=>2,'original_unit'=>'cup','scale_quantity'=>true]];
    $draft['steps']=[['id'=>'new-s','instruction'=>'Simmer'],['id'=>'new-s2','instruction'=>'Rest']];
    $draft['actions']=[['id'=>'new-a','step_id'=>'new-s','action_type'=>'timer','duration_seconds'=>60],['id'=>'new-a2','step_id'=>'new-s2','action_type'=>'internal_temperature','temperature_value'=>165,'temperature_unit'=>'F']];
    $draft['tags']=[['kind'=>'cuisine','label'=>'French']]; $draft['category_assignments']=[['category_id'=>'1']]; $draft['pairings']=[['id'=>$pair,'notes'=>'Serve together']];
    $draft['nutrition']=['basis'=>'per_serving','method'=>'manual','calories_kcal'=>'100','is_estimate'=>true]; $draft['nutrition_confirmed']=true;
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT1sAAAAASUVORK5CYII=';
    $draft['photos']=[['id'=>'new-p','image_base64'=>$png,'caption'=>'Plate','is_cover'=>true]];
    $saved=$edit->save($a['id'],$h,$id,$draft);
    editExpect($saved['title']==='Edited' && $saved['revision']==='2','Recipe not saved.');
    editExpect($saved['ingredients'][0]['group_id']===$saved['groups'][0]['id'],'Group association lost.');
    editExpect($saved['actions'][0]['step_id']===$saved['steps'][0]['id'],'Step association lost.');
    editExpect($saved['ingredients'][0]['quantity_numerator']==1 && $saved['ingredients'][0]['quantity_denominator']==2,'Fraction lost.');
    editExpect($saved['pairings'][0]['notes']==='Serve together','Pairing notes lost.');
    editExpect(!$saved['nutrition']['stale'],'Confirmed nutrition is stale.');
    editExpect(count(glob($media.'/*.png'))===1 && $saved['photos'][0]['width_pixels']==1,'Photo not stored.');
    editExpect(!isset($saved['source_text']) && $pdo->query("SELECT source_text FROM recipes WHERE id=$id")->fetchColumn()==='Private extraction','Source extraction changed.');
    editReject(fn()=>$edit->save($a['id'],$h,$id,$draft),409);
    editReject(fn()=>$edit->save($b['id'],$h,$id,$saved),404);
    editReject(fn()=>$edit->save($a['id'],$h,$foreign,$saved),404);
    $bad=$saved; $bad['actions'][0]['duration_seconds']=0;
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    editExpect($read->detail($a['id'],$h,$id)===$saved,'Invalid save was not rolled back.');
    $bad=$saved; $bad['ingredients'][0]['id']=$foreign;
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    $bad=$saved; $bad['parent_recipe_id']='999999999999';
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    $bad=$saved; $bad['parent_recipe_id']=$id;
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    $bad=$saved; $bad['photos'][]=['id'=>'new-invalid','image_base64'=>base64_encode('<?php echo 1;'),'is_cover'=>false];
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    $bad=$saved; $bad['tags'][]=['kind'=>'cuisine','label'=>'French'];
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    $bad=$saved; $bad['photos'][]=['id'=>'new-p2','image_base64'=>$png,'is_cover'=>true];
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    editExpect(count(glob($media.'/*.png'))===1,'Failed save left uploaded files.');
    $bad=$saved; $bad['photos'][]=['id'=>'new-p2','image_base64'=>$png,'is_cover'=>false]; $bad['tags'][]=['kind'=>'bad','label'=>'Bad'];
    editReject(fn()=>$edit->save($a['id'],$h,$id,$bad),422);
    editExpect(count(glob($media.'/*.png'))===1,'Rollback did not clean newly written file.');
    $next=$saved; $next['steps']=array_reverse($next['steps']); $next['ingredients'][0]['ingredient_name']='cream';
    $next['photos'][0]['source_url']='https://attacker.test/changed';
    $result=$edit->save($a['id'],$h,$id,$next);
    editExpect($result['steps'][1]['id']===$saved['steps'][0]['id'] && $result['ingredients'][0]['id']===$saved['ingredients'][0]['id'],'Stable IDs changed.');
    editExpect($result['actions'][0]['id']===$saved['actions'][0]['id'],'Action ID changed.');
    editExpect($result['photos'][0]['source_url']===$saved['photos'][0]['source_url'],'Client changed protected photo metadata.');
    editExpect($result['nutrition']['stale'],'Unconfirmed nutrition should become stale.');
    $result['steps']=[]; $result['actions']=[]; $result['ingredients']=[]; $result['groups']=[]; $result['photos']=[]; $result['nutrition']=null; $result['pairings']=[]; $result['tags']=[]; $result['category_assignments']=[];
    $empty=$edit->save($a['id'],$h,$id,$result);
    editExpect($empty['steps']===[] && $empty['photos']===[] && $empty['nutrition']===null && $empty['pairings']===[],'Removal failed.');
    editExpect($pdo->query("SELECT revision FROM recipes WHERE id=$foreign")->fetchColumn()==1,'Foreign recipe changed.');
    $categoryCalls=0;
    $categoryPhoto=new RecipePhotoService(['api_key'=>'fixture'],$media,function($payload) use (&$categoryCalls) {
        $categoryCalls++;
        editExpect(str_contains($payload['input'],'Cookies') && str_contains($payload['input'],'Desserts'),'Category image did not use its specific label.');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'image_generation_call','status'=>'completed','result'=>'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==']]])];
    });
    $categoryImages=new CategoryImageService($pdo,$categoryPhoto,$media,'https://example.test/media/recipes');
    editReject(fn()=>$categoryImages->ensure((string)$cookieCategory,$a['id'],$h),403);
    editExpect($categoryCalls===0, 'Disabled household reached the AI image provider.');
    $pdo->prepare('UPDATE households SET ai_images_enabled = 1 WHERE id = ?')->execute([$h]);
    $categoryUrl=$categoryImages->ensure((string)$cookieCategory,$a['id'],$h);
    editExpect($categoryUrl==='https://example.test/media/recipes/category-'.$cookieCategory.'.jpg' && is_file($media.'/category-'.$cookieCategory.'.jpg'),'Category image was not published.');
    $pdo->prepare('UPDATE households SET ai_images_enabled = 0 WHERE id = ?')->execute([$h]);
    editExpect($categoryImages->ensure((string)$cookieCategory,$a['id'],$h)===$categoryUrl && $categoryCalls===1,'Existing category image is unavailable or generated twice after disabling.');
    editReject(fn()=>$categoryImages->ensure((string)$cookieCategory,$b['id'],$h),404);
    editReject(fn()=>$categoryImages->ensure((string)$cookieCategory,$b['id'],$other),404);
    echo "$checks recipe edit checks passed.\n";
} finally {
    $admin->exec("DROP DATABASE `$name`");
    foreach (glob($media.'/*') ?: [] as $file) unlink($file);
    if (is_dir($media)) rmdir($media);
}
