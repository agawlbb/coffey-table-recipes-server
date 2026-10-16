<?php
declare(strict_types=1);
require dirname(__DIR__).'/bin/reviewed-recipe-groups-plan.php';
$plans=reviewedRecipeGroupsPlan(); $checks=0;
$expect=static function(bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$ids=array_keys($plans); sort($ids);
$expect($ids===[10,11,14,18,24,28,37,40,43],'Only the nine approved recipes may change.');
foreach ($plans as $id=>$plan) {
    $positions=[];
    foreach ($plan['groups'] as $title=>$members) {
        $expect(!preg_match('/decoration|garnish|assembly|whipped/i',$title),'Unapproved finishing group.');
        foreach ($members as $position) {
            $expect($position>0 && !isset($positions[$position]),'Duplicate/invalid component assignment.');
            $positions[$position]=$title;
        }
    }
    $expected=$id===18?7:($id===24?13:$plan['count']);
    $expect(max(array_keys($positions))<=$expected,'Out-of-range ingredient assignment.');
    $expect(count($positions)===($id===43?6:($id===24?12:$expected)),'Unexpected ungrouped ingredients.');
    $expect(preg_match('/^[a-f0-9]{64}$/',$plan['fingerprint'])===1,'Missing reviewed-content fingerprint.');
}
$expect($plans[11]['groups']['Frosting']===[11,12,13,14,15,16],'Cake decoration must stay with frosting.');
$expect(!in_array(7,array_merge(...array_values($plans[43]['groups'])),true),'Purchased ice cream must stay ungrouped.');
$expect(!in_array(8,array_merge(...array_values($plans[43]['groups'])),true),'Whipped topping must stay ungrouped.');
$expect($plans[24]['groups']['Meringue']===[11,12,13],'Meringue must include separated whites, sugar and salt.');
$expect($plans[18]['groups']['Mint syrup']===[1,2,3] && $plans[18]['groups']['Cocktail']===[4,5,6,7],'Syrup batch and single-drink ingredients must stay separate.');
echo "$checks reviewed grouping checks passed.\n";
