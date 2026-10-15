<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
require dirname(__DIR__).'/src/RecipeVariationService.php';
$checks=0;
function expectVariation(bool $value,string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function variationError(callable $operation,string $code): void { try { $operation(); } catch (HttpError $error) { expectVariation($error->errorCode===$code,'Expected '.$code.', got '.$error->errorCode); return; } throw new RuntimeException('Expected '.$code); }
variationError(fn()=>RecipeVariationService::accessPolicy([]),'variation_unavailable');
variationError(fn()=>RecipeVariationService::accessPolicy(['variation_access'=>'typo']),'variation_unavailable');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'all_members'])==='ai_images','Unknown/paid billing uses image allowlist');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'all_members','variation_billing'=>'complimentary_verified'])==='all_members','Explicit verified complimentary config');
expectVariation(RecipeVariationService::accessPolicy(['variation_access'=>'ai_images','variation_billing'=>'complimentary_verified'])==='ai_images','Narrow policy preserved');
$directory=sys_get_temp_dir().'/variation-tests-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$payloads=[];
$config=['api_key'=>'mock-only','model'=>'gpt-5.4-mini','variation_model'=>'fixture-model','variation_access'=>'ai_images'];
$transport=static function(array $payload) use (&$payloads): array {
    $payloads[]=$payload;
    $result=['recipe'=>['title'=>'Chickpea curry','ingredients'=>[['original_text'=>'1 can chickpeas','ingredient_name'=>'chickpeas']],'steps'=>[['instruction'=>'Simmer the chickpeas.']]],'questions'=>count($payloads)===1 ? ['How spicy?'] : []];
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($result)]]]]])];
};
$service=new RecipeVariationService($config,$directory,$transport);
$source=['id'=>'4','title'=>'Chicken curry','household_id'=>'secret-household','created_by_user_id'=>'secret-person','related'=>[['title'=>'secret-other-recipe']],'photos'=>[['source_url'=>'secret-url']],'nutrition'=>['calories_kcal'=>999],'ingredients'=>[['id'=>'secret-ingredient-id','original_text'=>'1 chicken','ingredient_name'=>'chicken']],'steps'=>[['id'=>'secret-step-id','instruction'=>'Cook chicken.']]];
$source['groups']=[['id'=>'secret-group-1','title'=>'Sauce'],['id'=>'secret-group-2','title'=>'Topping']];
$source['ingredients'][0]['group_id']='secret-group-1';
$source['ingredients'][]=['id'=>'secret-ingredient-2','group_id'=>'secret-group-2','original_text'=>'1 chicken','ingredient_name'=>'chicken'];
$source['actions']=[
    ['id'=>'secret-action-1','step_id'=>'secret-step-id','action_type'=>'appliance_temperature','label'=>'Preheat','temperature_value'=>180,'temperature_unit'=>'C','appliance'=>'oven'],
    ['id'=>'secret-action-2','step_id'=>'secret-step-id','action_type'=>'timer','label'=>'Cook','duration_seconds'=>1200],
];
$body=['changes'=>'Use chickpeas instead of chicken','request_id'=>str_repeat('a',32),'parent_recipe_id'=>'999','recipe'=>['id'=>'999']];
try {
    $draft=$service->analyze($source,$body,'user-a','house-a');
    expectVariation(count($payloads)===1,'One mock call');
    expectVariation($payloads[0]['model']==='fixture-model' && $payloads[0]['store']===false,'Configured model and no response storage');
    expectVariation(str_contains($payloads[0]['instructions'],'Create ONE practical recipe variation'),'Variation task instructions');
    $serialized=json_encode($payloads[0]);
    foreach(['secret-household','secret-person','secret-other-recipe','secret-url','secret-ingredient-id','secret-step-id','secret-group-1','secret-group-2','secret-ingredient-2','secret-action-1','secret-action-2'] as $secret) expectVariation(!str_contains($serialized,$secret),'Only cooking content sent');
    $content=$payloads[0]['input'][0]['content'][0]['text'];
    $submitted=json_decode(substr($content,strpos($content,'{')),true)['original_recipe'];
    expectVariation(array_column($submitted['ingredients'],'group_title')===['Sauce','Topping'],'Duplicate ingredient group context retained');
    expectVariation($submitted['steps'][0]['actions'][0]['temperature_value']===180 && $submitted['steps'][0]['actions'][0]['temperature_unit']==='C','Structured-only oven temperature retained');
    expectVariation($submitted['steps'][0]['actions'][1]['duration_seconds']===1200,'Structured-only timer retained');
    expectVariation($draft['recipe']['parent_recipe_id']==='4' && !isset($draft['recipe']['id']),'Server owns relationship; new draft');
    expectVariation($draft['recipe']['source_type']==='ai_variation' && $draft['recipe']['is_variation']===true,'Explicit variation provenance');
    expectVariation($draft['recipe']['photos']===[] && $draft['recipe']['nutrition']===null,'No copied photos or nutrition');
    expectVariation($service->analyze($source,$body,'user-a','house-a')===$draft && count($payloads)===1,'Retry reuses draft');
    $answered=$body+['answers'=>[['question'=>'How spicy?','answer'=>'Mild']]];
    $ready=$service->analyze($source,$answered,'user-a','house-a');
    expectVariation($ready['questions']===[] && count($payloads)===2,'Answers produce next draft');
    expectVariation($ready['recipe']['import_key']===$draft['recipe']['import_key'],'Stable save identity across clarification');
    expectVariation($service->analyze($source,$body,'user-a','house-a')===$draft && count($payloads)===2,'Earlier round retry cached');
    $other=$service->analyze($source,$body,'user-b','house-a');
    expectVariation($other['recipe']['import_key']!==$draft['recipe']['import_key'],'User cache isolation');
    $other=$service->analyze($source,$body,'user-a','house-b');
    expectVariation($other['recipe']['import_key']!==$draft['recipe']['import_key'],'Household cache isolation');
    variationError(fn()=>$service->analyze($source,array_replace($body,['changes'=>'Different']),'user-a','house-a'),'variation_changed');
    foreach([['request_id'=>'../unsafe'],['changes'=>''],['answers'=>[['question'=>[], 'answer'=>'x']]],['changes'=>str_repeat('x',4001)]] as $invalid) variationError(fn()=>$service->analyze($source,array_replace($body,$invalid),'user-a','house-a'),'invalid_variation');
    $failed=new RecipeVariationService($config,$directory,static fn()=>['status'=>429,'body'=>'{}']);
    $retryBody=array_replace($body,['request_id'=>str_repeat('b',32)]);
    variationError(fn()=>$failed->analyze($source,$retryBody,'user-a','house-a'),'ai_unavailable');
    $retry=$service->analyze($source,$retryBody,'user-a','house-a');
    expectVariation(isset($retry['recipe']['import_key']),'Failure does not poison retry');
    $roundBody=array_replace($body,['request_id'=>str_repeat('c',32)]);
    for ($round=0;$round<8;$round++) $service->analyze($source,$roundBody+['answers'=>[['question'=>'Preference?','answer'=>(string)$round]]],'user-a','house-a');
    variationError(fn()=>$service->analyze($source,$roundBody+['answers'=>[['question'=>'Preference?','answer'=>'ninth']]],'user-a','house-a'),'variation_limit');
    $route=file_get_contents(dirname(__DIR__).'/index.php');
    $section=substr($route,strpos($route,"$"."variationMatch))"),4000);
    expectVariation(strpos($section,"requireAiImages")<strpos($section,"->analyze("),'Family authorization precedes generation');
    expectVariation(strpos($section,"->detail(")<strpos($section,"->analyze("),'Household-visible source loaded before generation');
} finally { foreach(glob($directory.'/variation-drafts/*') ?: [] as $file) unlink($file); if(is_dir($directory.'/variation-drafts'))rmdir($directory.'/variation-drafts'); rmdir($directory); }
echo "$checks recipe variation checks passed.\n";
