<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
final class ShoppingDatabase extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$pdo = new ShoppingDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE households (id INTEGER PRIMARY KEY);
CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE shopping_lists (household_id INTEGER PRIMARY KEY,revision INTEGER,items TEXT);
INSERT INTO households VALUES (1),(2);
INSERT INTO household_memberships VALUES (1,1,'owner'),(1,2,'member'),(2,3,'owner');");
$service = new ShoppingListService($pdo, new HouseholdService($pdo));
function checkShopping(bool $ok): void { if (!$ok) throw new RuntimeException('Shopping list assertion failed'); }
function rejectShopping(callable $call, int $status): void {
    try { $call(); throw new RuntimeException('Expected rejection'); }
    catch (HttpError $error) { checkShopping($error->status === $status); }
}
checkShopping($service->read('1','1')['revision'] === 0 && $service->read('1','1')['items'] === []);
$milk = ['id'=>'milk','name'=>' Milk ','department'=>'Dairy & eggs','checked'=>false];
$apple = ['id'=>'apple','name'=>'Apples','department'=>'Produce','checked'=>false];
$saved = $service->save('1','1',['revision'=>0,'items'=>[$milk,$apple]]);
checkShopping($saved['items'][0]['name'] === 'Milk');
checkShopping($service->read('2','1') === $saved);
$apple['checked'] = true;
$saved = $service->save('2','1',['revision'=>1,'items'=>[$apple,$milk]]);
checkShopping($saved['revision']===2 && $saved['items'][0]['checked']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>1,'items'=>[]]),409);
checkShopping($service->read('1','1') === $saved);
rejectShopping(fn()=>$service->read('3','1'),404);
rejectShopping(fn()=>$service->save('3','1',['revision'=>2,'items'=>[]]),404);
checkShopping($service->read('3','2')['items']===[]);
foreach ([[$milk,$milk], [array_replace($milk,['checked'=>'true'])], [array_replace($milk,['department'=>'Unknown'])], [array_replace($milk,['name'=>' '])], array_fill(0,501,$milk)] as $invalid) {
    rejectShopping(fn()=>$service->save('1','1',['revision'=>2,'items'=>$invalid]),422);
}
$saved = $service->save('1','1',['revision'=>2,'items'=>[$milk]]);
checkShopping(count($saved['items'])===1 && $saved['items'][0]['id']==='milk');
checkShopping($service->save('2','1',['revision'=>3,'items'=>[]])['items']===[]);
$unassigned = array_replace($apple, ['department'=>'', 'checked'=>false]);
$saved = $service->save('1','1',['revision'=>4,'items'=>[$unassigned,$milk]]);
checkShopping($service->read('2','1')['items'][0]['department']==='');
checkShopping($saved['items'][1]['department']==='Dairy & eggs');
$assigned = array_replace($unassigned, ['department'=>'Produce']);
$service->save('2','1',['revision'=>5,'items'=>[$assigned,$milk]]);
checkShopping($service->read('1','1')['items'][0]['department']==='Produce');
$service->save('1','1',['revision'=>6,'items'=>[$unassigned,$milk]]);
checkShopping($service->read('2','1')['items'][0]['department']==='');
rejectShopping(fn()=>$service->save('1','1',['revision'=>7,'items'=>[array_replace($apple,['department'=>null])]]),422);
echo "Shopping list sharing, ordering, validation, isolation and conflict checks passed\n";

// Department configuration is shared and survives writes from older clients.
$saved = $service->save('1','1',['revision'=>7,'departments'=>['Farm stand','Dairy & eggs'], 'items'=>[array_replace($apple,['department'=>'Farm stand']),$milk]]);
checkShopping($service->read('2','1')['departments'] === ['Farm stand','Dairy & eggs']);
$saved = $service->save('2','1',['revision'=>8,'items'=>$saved['items']]);
checkShopping($saved['departments'] === ['Farm stand','Dairy & eggs']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>['Farm stand','farm stand'],'items'=>[]]),422);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>[''],'items'=>[]]),422);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>null,'items'=>[]]),422);
rejectShopping(fn()=>$service->save('3','1',['revision'=>9,'departments'=>[],'items'=>[]]),404);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>['Dairy & eggs'],'items'=>$saved['items']]),422);
$saved['items'][0]['department']='';
$saved=$service->save('2','1',['revision'=>9,'departments'=>['Dairy & eggs'],'items'=>$saved['items']]);
checkShopping($saved['items'][0]['department']==='' && $saved['departments']===['Dairy & eggs']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>[],'items'=>[]]),409);
checkShopping($service->read('1','1')===$saved);
// Explicitly verify legacy storage remains readable.
$pdo->prepare('UPDATE shopping_lists SET items=? WHERE household_id=1')->execute([json_encode([$milk])]);
checkShopping($service->read('1','1')['items'][0]['name']===' Milk ');
checkShopping(in_array('Produce',$service->read('1','1')['departments'],true));
echo "Department configuration and legacy compatibility checks passed\n";
