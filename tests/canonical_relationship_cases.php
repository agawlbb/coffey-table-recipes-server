<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/RecipeShare.php';
// Shared by the SQLite fixture and explicitly isolated MariaDB rehearsal.
function canonicalRelationshipCases(PDO $pdo, HouseholdService $households, string $a, string $ha, string $b, string $hb, callable $check): void {
    $edit=new RecipeEditorService($pdo,$households,sys_get_temp_dir(),'https://example.invalid');
    $read=new RecipeService($pdo,$households); $links=new RecipeMembership($pdo,$households);
    $base=['title'=>'Canonical original','original_language'=>'en','measurement_system'=>'metric','ingredients'=>[],'groups'=>[],'steps'=>[],'actions'=>[],'photos'=>[],'tags'=>[],'category_assignments'=>[],'pairings'=>[]];
    $original=$edit->save($a,$ha,'',$base); $id=$original['id']; $links->attach($b,$hb,$id);
    $variation=$base; $variation['parent_recipe_id']=$id; $variation['source_type']='ai_variation';
    $variation['import_key']=hash('sha256','canonical-a'); $variation['title']='A variation';
    $sibling=$edit->save($a,$ha,'',$variation);
    $variation['import_key']=hash('sha256','canonical-b'); $variation['title']='B variation';
    $child=$edit->save($b,$hb,'',$variation);
    $check($child['parent_recipe_id']===$id && $child['id']!==$id,'Second household variation must link the same canonical original.');
    $check($child['household_count']===1 && $child['in_household']===true,'Variation must attach only to the requesting household.');
    $check($read->detail('',$ha,$child['id'],true)['in_household']===false,'Variation must not silently attach to original household.');
    $check($edit->save($b,$hb,'',$variation)['id']===$child['id'],'Cross-household variation retry duplicated content.');
    $related=array_column($child['related'],'relationship','id');
    $check(($related[$id]??null)==='parent' && ($related[$sibling['id']]??null)==='sibling','Canonical parent/sibling navigation lost foreign-storage relationships.');
    $check(in_array($child['id'],array_column($read->detail($a,$ha,$id)['related'],'id'),true),'Original cannot navigate its foreign-storage child.');
    $check(in_array($sibling['id'],array_column($child['variation_family'],'id'),true),'Family menu lost a sibling outside the current household.');
    $check($read->detail('',$hb,$sibling['id'],true)['in_household']===false,'Public sibling navigation must keep attachment metadata accurate.');
    $check(!in_array($sibling['id'],array_column($read->browse($b,$hb)['recipes'],'id'),true),'Household browsing leaked an unattached sibling.');
    $check(!in_array($child['id'],array_column($read->browse($a,$ha)['recipes'],'id'),true),'Household browsing leaked another household variation.');
    $options=$read->editOptions($b,$hb,$id);
    $check(in_array($child['id'],array_column($options['recipes'],'id'),true),'Editor options use original storage instead of requesting attachments.');
    $paired=$read->detail($b,$hb,$id);$paired['confirm_shared_edit']=true;$paired['pairings']=[['id'=>$child['id'],'notes'=>'Together']];
    $paired=$edit->save($b,$hb,$id,$paired);
    $check(in_array($child['id'],array_column($paired['pairings'],'id'),true),'Cross-storage pairing rejected.');
    $check(in_array($id,array_column($read->detail($b,$hb,$child['id'])['pairings'],'id'),true),'Canonical reverse pairing lost.');
    $public=RecipeShare::load($pdo,$child['id']);
    $check(in_array($sibling['id'],array_column($public['related'],'id'),true),'Web fallback lost canonical sibling navigation.');
    $check(in_array($id,array_column($public['pairings'],'id'),true),'Web fallback lost canonical reverse pairing.');
    $cycle=$read->detail($b,$hb,$id);$cycle['parent_recipe_id']=$child['id'];$cycle['confirm_shared_edit']=true;
    try {$edit->save($b,$hb,$id,$cycle);throw new RuntimeException('Cross-household cycle accepted.');} catch(HttpError $e) {$check($e->status===422,'Cross-household cycle must fail.');}
    try {$edit->save($a,$ha,$child['id'],$child);throw new RuntimeException('Unattached mutation accepted.');} catch(HttpError $e) {$check($e->status===404,'Canonical navigation must not authorize edits.');}
    // Removing the original from this list must not sever family traversal.
    (new RecipeDeletionService($pdo,$households))->delete($b,$hb,$id,['revision'=>$paired['revision']]);
    $check(in_array($id,array_column($read->detail($b,$hb,$child['id'])['variation_family'],'id'),true),'Detached parent broke canonical family traversal.');
    $check(!in_array($id,array_column($read->browse($b,$hb,'',0,'','',false,true)['recipes'],'id'),true),'Grouped browse returned detached family representative.');
}
