<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/HttpError.php';
require dirname(__DIR__).'/src/RecipeShare.php';
require dirname(__DIR__).'/src/RecipeSharePage.php';
$checks=0;
function checkShare(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
checkShare(RecipeShare::recipeId('9') === '9', 'Database ID preserved');
foreach ([null, [], '', '0', '-1', '9 OR 1=1', '1.2', str_repeat('9',20)] as $bad) {
    try { RecipeShare::recipeId($bad); throw new RuntimeException('Invalid ID accepted'); }
    catch (HttpError $error) { checkShare($error->status===404,'Invalid ID status'); }
}
$recipe=['title'=>'Crêpes <script>alert(1)</script>','description'=>'Family favourite','original_language'=>'fr','servings'=>'4.0000',
    'tags'=>[['label'=>'Dessert']],
    'groups'=>[['id'=>'4','title'=>'Batter']], 'ingredients'=>[['group_id'=>'4','original_text'=>'250 g flour']],
    'steps'=>[['id'=>'5','position'=>1,'instruction'=>'Bake <b>carefully</b>']],
    'actions'=>[['step_id'=>'5','label'=>'Oven','temperature_value'=>'180.0000','temperature_unit'=>'C']],
    'photos'=>[['source_url'=>'javascript:alert(1)'],['source_url'=>'https://example.com/crepes.jpg','caption'=>'Crêpes']],
    'nutrition'=>['is_estimate'=>1,'basis'=>'per_serving','calories_kcal'=>'123.0000','stale'=>true,'method'=>'Calculated'],
    'notes'=>'Rest the batter','source_text'=>'PRIVATE IMPORT','edit_options'=>['recipes'=>[['title'=>'PRIVATE RECIPE']]],
];
$html=recipeSharePage($recipe);
foreach (['Batter','250 g flour','4 servings','Calories (kcal): 123','Rest the batter','needs recalculation','&lt;script&gt;','&lt;b&gt;carefully&lt;/b&gt;','assets/email/coffey_table.jpg','background:transparent','mix-blend-mode:multiply','recipe-header','max-width:900px','#f5eddf'] as $text) checkShare(str_contains($html,$text),'Missing '.$text);
foreach (['<script>','javascript:','PRIVATE IMPORT','PRIVATE RECIPE','Oven','Original language:','Original measurements','Dessert'] as $text) checkShare(!str_contains($html,$text),'Leaked '.$text);
if (isset($argv[1])) file_put_contents($argv[1],$html);
echo "Recipe sharing: $checks checks passed.\n";
