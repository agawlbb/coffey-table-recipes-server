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
aiExpect(RecipeImportService::cleanText('&#x20;1/3 cup &amp; lime')==='1/3 cup & lime','Pasted HTML entities were not decoded.');
$input = ['recipe'=>['title'=>'Soup','ingredients'=>[['original_text'=>'Water','ingredient_name'=>'water','id'=>'999','scale_quantity'=>1]],'steps'=>[['instruction'=>'Boil']],'household_id'=>'another','nutrition'=>['calories'=>99]],'questions'=>['How much water?']];
$draft = RecipeImportService::draft($input);
$timed = $input;
$timed['recipe']['steps'][0]['actions'] = [['id'=>'999','step_id'=>'999','action_type'=>'timer','label'=>'Simmer','duration_seconds'=>120]];
$timed['recipe']['categories'] = [['label'=>'Cookies','parent_label'=>'Desserts','category_id'=>'999']];
$prepared = RecipeImportService::draft($timed)['recipe'];
aiExpect($prepared['actions'][0]['duration_seconds']===120 && $prepared['actions'][0]['step_id']==='new-steps-0' && $prepared['actions'][0]['id']==='new-actions-0','Timed action not associated with normalized step.');
aiExpect($prepared['category_assignments']===[['label'=>'Cookies','parent_label'=>'Desserts']],'Category proposal accepted untrusted ID.');
$pie=$input; $pie['recipe']['categories']=[['label'=>'Desserts','parent_label'=>''],['label'=>'Pies','parent_label'=>'Desserts']];
aiExpect(RecipeImportService::draft($pie)['recipe']['category_assignments']===[['label'=>'Pies','parent_label'=>'Desserts']],'Redundant parent category assignment remained.');
$catalog=[['id'=>'42','label'=>'Cookies','parent_label'=>'Desserts']];
aiExpect(RecipeImportService::draft($timed,$catalog)['recipe']['category_assignments'][0]['category_id']==='42','Existing category not resolved.');
foreach (['10 to 15 minutes','10–15 minutes','10-15 minutes','10 minutes to 15 minutes','15 to 10 minutes'] as $range) {
    $ranged=$input;
    $ranged['recipe']['steps'][0]['instruction']='Cook for '.$range.'.';
    $ranged['recipe']['steps'][0]['actions']=[['action_type'=>'timer','duration_seconds'=>900],['action_type'=>'timer','duration_seconds'=>600]];
    $actions=RecipeImportService::draft($ranged)['recipe']['actions'];
    aiExpect(count($actions)===1 && $actions[0]['duration_seconds']===600,'Wrong range timer: '.$range);
}
$heat=$input;
$heat['recipe']['steps'][0]['actions']=[['action_type'=>'appliance_temperature','label'=>'Simmer over low heat','temperature_value'=>null,'temperature_unit'=>'F']];
aiExpect(RecipeImportService::draft($heat)['recipe']['actions']===[], 'Qualitative heat should not create an action.');
$bad = $timed; $bad['recipe']['steps'][0]['actions'][0]['duration_seconds']=0;
aiExpect(RecipeImportService::draft($bad)['recipe']['actions']===[], 'Incomplete timers should be omitted, never turned into reminders.');
$bad['recipe']['steps'][0]['actions'][0]['action_type']='simmer';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions']===[], 'Unknown action should be omitted.');
foreach (['reminder','internal_temperature'] as $type) {
    $extra=$timed; $extra['recipe']['steps'][0]['actions'][0]=['action_type'=>$type,'label'=>'Cook','temperature_value'=>165,'temperature_unit'=>'F'];
    aiExpect(RecipeImportService::draft($extra)['recipe']['actions']===[], 'Unwanted action type survived filtering.');
}
$rest=$timed; $rest['recipe']['steps'][0]['actions'][0]['action_type']='rest';
aiExpect(RecipeImportService::draft($rest)['recipe']['actions'][0]['action_type']==='timer', 'Explicit timed resting must be a timer.');
$oven=$timed; $oven['recipe']['steps'][0]['actions'][0]=['action_type'=>'appliance_temperature','label'=>'Preheat oven','temperature_value'=>300,'temperature_unit'=>'F'];
aiExpect(RecipeImportService::draft($oven)['recipe']['actions'][0]['temperature_value']===300, 'Explicit appliance temperature was lost.');
$bad=$timed; $bad['recipe']['steps'][0]['actions'][0]['temperature_unit']='F';
aiExpect(RecipeImportService::draft($bad)['recipe']['actions'][0]['temperature_unit']===null,'Stray timer temperature unit remained unsavable.');
$format=RecipeImportService::responseFormat();
aiExpect($format['strict']===true && $format['type']==='json_schema','Strict response format missing.');
function checkObjects(array $schema): void { if (($schema['type'] ?? '')==='object') aiExpect($schema['additionalProperties']===false && $schema['required']===array_keys($schema['properties']),'Object is not strict.'); foreach ($schema['properties'] ?? [] as $child) checkObjects($child); if (isset($schema['items'])) checkObjects($schema['items']); }
checkObjects($format['schema']);
aiExpect($format['schema']['properties']['recipe']['properties']['description']['type']==='string','Missing descriptions remain nullable.');
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
$quantities=$input;
$quantities['recipe']['ingredients'][0]+= ['quantity_decimal'=>'0.5','quantity_numerator'=>1,'quantity_denominator'=>2];
$q=RecipeImportService::draft($quantities)['recipe']['ingredients'][0];
aiExpect($q['quantity_decimal']===null && $q['quantity_numerator']===1 && $q['quantity_denominator']===2,'Duplicate fraction and decimal not normalized.');
$quantities['recipe']['ingredients'][0]['quantity_denominator']=0;
$q=RecipeImportService::draft($quantities)['recipe']['ingredients'][0];
aiExpect($q['quantity_decimal']==='0.5' && $q['quantity_numerator']===null && $q['quantity_denominator']===null,'Placeholder fraction broke decimal.');
aiExpect(RecipeImportService::sourceType('text',[])==='Pasted text','Pasted source mislabeled.');
aiExpect(RecipeImportService::sourceType('text',['input_source'=>'written_description'])==='Written description of a dish','Written description mislabeled.');
aiExpect(RecipeImportService::sourceType('file',[],true)==='Uploaded PDF','PDF source mislabeled.');
aiExpect(RecipeImportService::sourceType('file',[])==='Uploaded image','Image source mislabeled.');
aiExpect(RecipeImportService::sourceType('answers',['recipe'=>['source_type'=>'Camera photo']])==='Camera photo','Clarification lost original source.');

// Exercise more than the former hourly quota without real upstream API calls.
$limitStorage = sys_get_temp_dir().'/coffey-ai-limit-test-'.bin2hex(random_bytes(6));
mkdir($limitStorage.'/recipe-ai',0700,true);
$legacyCounter = $limitStorage.'/recipe-ai/'.hash('sha256','repeat-user').'.json';
file_put_contents($legacyCounter,json_encode(['start'=>time(),'count'=>20]));
$calls = 0;
$repeatService = new RecipeImportService(['api_key'=>'test-fixture','model'=>'test-model'],$limitStorage,
    static function(array $payload) use (&$calls,$input,$catalog): array {
        $calls++;
        aiExpect(str_contains($payload['instructions'],'exactly ONE timer') && str_contains($payload['instructions'],'full parent and child category catalog'),'Missing range/category prompt rules.');
        aiExpect(str_contains(json_encode($payload['input']), 'Cookies'),'Category catalog missing from AI request.');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[
            ['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($input)]]],
        ]])];
    });
try {
    for ($i=0;$i<25;$i++) {
        $repeatDraft = $repeatService->analyze(['kind'=>'text','text'=>'Soup: boil water.'],'repeat-user',$catalog);
        aiExpect($repeatDraft['recipe']['title']==='Soup','Repeated import did not produce the expected draft.');
    }
    aiExpect($calls===25,'Hourly restriction blocked a repeated import.');
    aiExpect(json_decode(file_get_contents($legacyCounter),true)['count']===20,'Legacy hourly counter was modified.');
} finally {
    unlink($legacyCounter); rmdir($limitStorage.'/recipe-ai'); rmdir($limitStorage);
}
echo "$checks recipe AI preprocessing and validation checks passed.\n";
