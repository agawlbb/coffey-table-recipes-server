<?php
declare(strict_types=1);

function recipeSharePage(array $r): string {
    $e = static fn($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $number = static fn($v): string => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 8, '.', ''), '0'), '.') : (string) $v;
    $summary = [];
    if (isset($r['total_minutes'])) $summary[] = $r['total_minutes'].' min total';
    else foreach (['prep', 'cook'] as $kind) if (isset($r[$kind.'_minutes'])) $summary[] = $r[$kind.'_minutes'].' min '.$kind;
    if (isset($r['yield_text'])) $summary[] = $r['yield_text'];
    elseif (isset($r['servings'])) $summary[] = $number($r['servings']).' servings';
    ob_start();
    ?>
<!doctype html>
<html lang="<?= $e($r['original_language'] ?? 'en') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= $e($r['title']) ?> · Coffey Table Recipes</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5eddf;color:#3b2416;font:16px Roboto,Arial,sans-serif;line-height:1.5}
main{max-width:900px;margin:auto;padding:40px 24px 24px}.recipe-header{margin-bottom:24px}h1{font-size:clamp(34px,5vw,48px);line-height:1.15;font-weight:600;letter-spacing:-.025em;margin:0 0 18px}
.branding{display:flex;align-items:center;gap:12px;color:#465334;font-size:14px}.brand{width:64px;height:64px;object-fit:cover;mix-blend-mode:multiply}.branding span{letter-spacing:.035em}
h2{font-size:24px;font-weight:500;color:#465334;margin:32px 0 12px;padding-bottom:8px;border-bottom:1px solid #dfd1bb}h3{font-size:16px;font-weight:600;margin:12px 0 8px}p{white-space:pre-wrap;margin:8px 0 16px}.ingredient{margin:12px 0}.step{margin-bottom:20px;line-height:1.6}
.photos{display:flex;gap:16px;overflow-x:auto;margin:24px 0}.photos figure{margin:0;flex:0 0 100%}.photos img{width:100%;height:340px;object-fit:contain;border-radius:12px;background:transparent}figcaption{font-size:14px}.nutrition{display:flex;gap:8px 16px;flex-wrap:wrap}.source{overflow-wrap:anywhere}.warning{color:#a12c21}
@media(max-width:600px){main{padding:28px 24px}.photos img{height:260px}}@media print{.branding{display:none}.photos{display:block}.photos figure{break-inside:avoid}body{background:white}}
</style></head><body><main><header class="recipe-header">
<h1><?= $e($r['title']) ?></h1>
<div class="branding"><img class="brand" src="assets/email/coffey_table.jpg" alt="Coffey Table Recipes"><span>Coffey Table Recipes</span></div>
</header>
<?php if ($summary): ?><p><?= $e(implode(' · ', $summary)) ?></p><?php endif ?>
<?php if (isset($r['description'])): ?><p><?= $e($r['description']) ?></p><?php endif ?>
<div class="photos">
<?php $seen = []; foreach ($r['photos'] ?? [] as $photo): $url = $photo['source_url'] ?? ''; if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http','https'], true) || isset($seen[$url])) continue; $seen[$url] = true; ?>
<figure><img src="<?= $e($url) ?>" alt="<?= $e($photo['caption'] ?? $r['title']) ?>" loading="lazy"><?php if (!empty($photo['caption'])): ?><figcaption><?= $e($photo['caption']) ?></figcaption><?php endif ?></figure>
<?php endforeach ?></div>
<?php if (array_key_exists('ingredients', $r)): ?>
<h2>Ingredients</h2>
<?php if (!$r['ingredients']): ?><p>No ingredients recorded.</p><?php endif ?>
<?php foreach (array_merge([['id'=>null]], $r['groups'] ?? []) as $group): ?>
<?php if ($group['id'] !== null): ?><h3><?= $e($group['title']) ?></h3><?php endif ?>
<?php foreach ($r['ingredients'] as $ingredient): if (($ingredient['group_id'] ?? null) !== $group['id']) continue; ?><p class="ingredient">• <?= $e($ingredient['original_text']) ?></p><?php endforeach ?>
<?php endforeach ?>
<h2>Method</h2>
<?php if (empty($r['steps'])): ?><p>No cooking steps recorded.</p><?php endif ?>
<?php foreach ($r['steps'] ?? [] as $step): ?><div class="step">
<?php if (isset($step['section_title'])): ?><h3><?= $e($step['section_title']) ?></h3><?php endif ?>
<p><?= $e($step['position']) ?>. <?= $e($step['instruction']) ?></p>
</div><?php endforeach ?>
<?php endif ?>
<?php if (isset($r['notes'])): ?><h2>Notes</h2><p><?= $e($r['notes']) ?></p><?php endif ?>
<?php $n=$r['nutrition'] ?? null; if ($n): ?><h2>Nutrition</h2><p><?= ($n['is_estimate'] ?? 0) == 1 ? 'Estimated' : 'Reported' ?> · <?= ($n['basis'] ?? '') === 'per_serving' ? 'Per serving' : 'Whole recipe' ?></p>
<?php if ($n['stale'] ?? false): ?><p class="warning">This estimate needs recalculation after recipe changes.</p><?php endif ?>
<div class="nutrition"><?php foreach (['calories_kcal'=>'Calories (kcal)','protein_g'=>'Protein (g)','carbohydrate_g'=>'Carbohydrates (g)','fat_g'=>'Fat (g)','saturated_fat_g'=>'Saturated fat (g)','fiber_g'=>'Fiber (g)','sugar_g'=>'Sugar (g)','sodium_mg'=>'Sodium (mg)'] as $key=>$label): if (isset($n[$key])): ?><span><?= $e($label) ?>: <?= $e($number($n[$key])) ?></span><?php endif; endforeach ?></div><p>Method: <?= $e($n['method'] ?? '') ?></p><?php endif ?>
<?php foreach (['related'=>'Recipe family','pairings'=>'Serve alongside'] as $key=>$heading): if (!empty($r[$key])): ?><h2><?= $heading ?></h2><?php foreach ($r[$key] as $row): ?><p><?= $e($row['title']) ?><?php if ($key === 'related'): ?><br><?= $row['id'] === ($r['parent_recipe_id'] ?? null) ? 'Parent recipe' : 'Variation' ?><?php endif ?></p><?php endforeach; endif; endforeach ?>
<?php $source=array_filter(array_map(static fn($key)=>$r[$key] ?? null, ['source_title','source_author','source_url','source_reference']), static fn($v)=>$v!==null); if ($source): ?><h2>Source</h2><?php foreach ($source as $value): ?><p class="source"><?= $e($value) ?></p><?php endforeach; endif ?>
<footer><a href="https://github.com/agawlbb/coffey-table-recipes-server">Source code (AGPL-3.0)</a></footer></main></body></html>
<?php return ob_get_clean();
}
