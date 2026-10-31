<?php
declare(strict_types=1);
// Exercise the actual HTTP router in child processes, with no credentials or DB.
$root=dirname(__DIR__);
function routeCheck(string $route,int $expected,string $code=''): void {
    global $root;
    $script='$_SERVER["REQUEST_URI"]='.var_export('/CoffeyTableRecipes/api/v1'.$route,true).';'.
        '$_SERVER["REQUEST_METHOD"]="GET"; register_shutdown_function(function(){echo "\\nSTATUS:".http_response_code();}); require '.var_export($root.'/index.php',true).';';
    $process=proc_open([PHP_BINARY,'-r',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if ($status!==0 || !str_ends_with($output,'STATUS:'.$expected)) throw new RuntimeException('Unexpected response for '.$route.': '.$output.$errors);
    if ($code!=='' && !str_contains($output,'"code":"'.$code.'"')) throw new RuntimeException('Unexpected route error for '.$route);
}
foreach (['/recipes/1','/households/1/recipes','/households/1/recipes/1','/households/1/recipes/1/edit-options','/households/1/recipes/1/favorites',
    '/households/1/recipes/deleted','/households/1/recipe-blank','/households/1/name','/households/1/people/me/name','/households/1/people','/households/1/snapshot','/households/1/restaurants',
    '/households/1/meal-plan','/households/1/meal-plan/history','/households/1/shopping-list',
    '/me/timers','/me/timer-devices','/me/timer-devices/remove'] as $route) routeCheck($route,410,'data_moved');
routeCheck('/health',200);
foreach (['/me','/households','/me/verification-email','/households/1/people/2/invitations','/invitations/3/respond'] as $route) routeCheck($route,410,'account_moved');
routeCheck('/me/child-mode',401,'authentication_required');
foreach (['/households/1/recipe-import','/households/1/recipe-category','/households/1/recipe-photo',
    '/households/1/recipes/1/variation','/households/1/category-image','/admin/parent-categories','/admin/parent-categories/10/category-image'] as $route) routeCheck($route,410,'ai_moved');

echo "InMotion retired data, account and AI routes; retained authenticated child policy passed without database access\n";
