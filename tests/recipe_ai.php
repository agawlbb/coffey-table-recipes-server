<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeImportService.php';
$checks = 0;
function aiExpect(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function aiReject(callable $action, int $status): void {
    try { $action(); } catch (HttpError $e) { aiExpect($e->status === $status, 'Unexpected status.'); return; }
    throw new RuntimeException('Unsafe input was accepted.');
}
$html = '<html><script type="application/ld+json">{"@graph":[{"@type":["Recipe"],"name":"Soup","recipeIngredient":["2 cups water"],"recipeInstructions":[{"@type":"HowToStep","text":"Boil"}],"advertising":"junk"}]}</script><nav>Ads</nav><main>Body</main></html>';
$text = RecipeImportService::websiteText($html);
aiExpect(str_contains($text,'Soup') && str_contains($text,'2 cups water') && !str_contains($text,'advertising'), 'Structured recipe extraction failed.');
aiExpect(RecipeImportService::websiteText('<nav>Noise</nav><article>Recipe instructions</article><script>tracking()</script>') === 'Recipe instructions','Fallback retained page noise.');
aiExpect(RecipeImportService::cleanText("  mix\x00   well\n\n\nserve  ") === "mix well\n\nserve",'Text cleanup failed.');
foreach (['127.0.0.1','10.0.0.1','192.168.1.5','169.254.169.254','::1','::ffff:127.0.0.1','100.64.0.1','224.0.0.1','198.18.0.1','ff02::1'] as $ip) aiReject(fn()=>RecipeImportService::publicAddresses($ip),422);
$input = ['recipe'=>['title'=>'Soup','ingredients'=>[['original_text'=>'Water','ingredient_name'=>'water','id'=>'999','scale_quantity'=>1]],'steps'=>[['instruction'=>'Boil']],'household_id'=>'another','nutrition'=>['calories'=>99]],'questions'=>['How much water?']];
$draft = RecipeImportService::draft($input);
$timed = $input;
$timed['recipe']['steps'][0]['actions'] = [['id'=>'999','step_id'=>'999','action_type'=>'timer','label'=>'Simmer','duration_seconds'=>120]];
$timed['recipe']['categories'] = [['label'=>'Cookies','parent_label'=>'Desserts','category_id'=>'999']];
$prepared = RecipeImportService::draft($timed)['recipe'];
aiExpect($prepared['actions'][0]['duration_seconds']===120 && $prepared['actions'][0]['step_id']==='new-steps-0' && $prepared['actions'][0]['id']==='new-actions-0','Timed action not associated with normalized step.');
aiExpect($prepared['category_assignments']===[['label'=>'Cookies','parent_label'=>'Desserts']],'Category proposal accepted untrusted ID.');
$heat=$input;
$heat['recipe']['steps'][0]['actions']=[['action_type'=>'appliance_temperature','label'=>'Simmer over low heat','temperature_value'=>null,'temperature_unit'=>'F']];
$heatAction=RecipeImportService::draft($heat)['recipe']['actions'][0];
aiExpect($heatAction['action_type']==='reminder' && $heatAction['temperature_unit']===null && $heatAction['label']==='Simmer over low heat','Qualitative heat did not become a reminder.');
$bad = $timed; $bad['recipe']['steps'][0]['actions'][0]['duration_seconds']=0;
$recovered=RecipeImportService::draft($bad)['recipe'];
aiExpect($recovered['actions'][0]['action_type']==='reminder' && $recovered['actions'][0]['duration_seconds']===null && str_contains($recovered['notes'],'Review step 1'),'Incomplete timer should remain reviewable without guessing.');
$bad['recipe']['steps'][0]['actions'][0]['action_type']='simmer';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions'][0]['action_type']==='reminder','Unknown action lost instead of preserved.');
$bad=$timed; $bad['recipe']['steps'][0]['actions'][0]['temperature_unit']='F';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions'][0]['temperature_unit']===null,'Stray timer temperature unit remained unsavable.');
$format=RecipeImportService::responseFormat();
aiExpect($format['strict']===true && $format['type']==='json_schema','Strict response format missing.');
function checkObjects(array $schema): void { if (($schema['type'] ?? '')==='object') aiExpect($schema['additionalProperties']===false && $schema['required']===array_keys($schema['properties']),'Object is not strict.'); foreach ($schema['properties'] ?? [] as $child) checkObjects($child); if (isset($schema['items'])) checkObjects($schema['items']); }
checkObjects($format['schema']);
$bad = $timed; $bad['recipe']['categories'][0]['label']=['bad']; aiReject(fn()=>RecipeImportService::draft($bad),502);
aiExpect($draft['recipe']['ingredients'][0]['id']==='new-ingredients-0','AI supplied a database ID.');
aiExpect(!isset($draft['recipe']['household_id']) && !isset($draft['recipe']['nutrition']),'Untrusted database fields were accepted.');
aiExpect($draft['questions']===['How much water?'] && $draft['recipe']['measurement_system']==='unspecified','Questions or defaults lost.');
$bad = $input; $bad['questions']=[['not a question']]; aiReject(fn()=>RecipeImportService::draft($bad),502);
$bad = $input; $bad['recipe']['steps']=['unexpected'=>'value']; aiReject(fn()=>RecipeImportService::draft($bad),502);
$service = new RecipeImportService(['api_key'=>'','model'=>'gpt-5.4-mini'],sys_get_temp_dir());
aiReject(fn()=>$service->analyze(['kind'=>'file','file_base64'=>base64_encode('<?php bad();')],'1'),422);
aiReject(fn()=>$service->analyze(['kind'=>'text','text'=>''],'1'),422);
aiReject(fn()=>$service->analyze(['kind'=>'website','url'=>'http://127.0.0.1'],'1'),422);
echo "$checks recipe AI preprocessing and validation checks passed.\n";
