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
*{box-sizing:border-box}body{margin:0;background:#f5eddf;color:#3b2416;font:16px Roboto,Arial,sans-serif;line-height:1.5}header{padding:16px 24px;color:#465334;font-size:20px}main{max-width:900px;margin:auto;padding:24px}h1{font-size:32px;line-height:1.25;font-weight:400;margin:0 0 16px}h2{font-size:24px;font-weight:400;margin:28px 0 12px}h3{font-size:16px;font-weight:500;margin:12px 0 8px}p{white-space:pre-wrap;margin:8px 0 16px}.chip{display:inline-block;border:1px solid #858c79;border-radius:8px;padding:5px 12px;margin:4px 8px 4px 0;background:#fff9ee}.ingredient{margin:12px 0}.step{margin-bottom:20px;line-height:1.6}.photos{display:flex;gap:16px;overflow-x:auto;margin:20px 0}.photos figure{margin:0;flex:0 0 100%}.photos img{width:100%;height:340px;object-fit:contain;border-radius:12px;background:#fff9ee}figcaption{font-size:14px}.nutrition{display:flex;gap:8px 16px;flex-wrap:wrap}.source{overflow-wrap:anywhere}.warning{color:#a12c21}@media(max-width:600px){main{padding:24px}header{font-size:18px}.photos img{height:260px}}@media print{header{display:none}.photos{display:block}.photos figure{break-inside:avoid}body{background:white}}
</style></head><body><header>Coffey Table Recipes</header><main>
<h1><?= $e($r['title']) ?></h1>
<?php if ($summary): ?><p><?= $e(implode(' · ', $summary)) ?></p><?php endif ?>
<?php if (isset($r['original_language'])): ?><p>Original language: <?= $e($r['original_language']) ?> · Original measurements</p><?php endif ?>
<?php if (isset($r['description'])): ?><p><?= $e($r['description']) ?></p><?php endif ?>
<div class="photos">
<?php $seen = []; foreach ($r['photos'] ?? [] as $photo): $url = $photo['source_url'] ?? ''; if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http','https'], true) || isset($seen[$url])) continue; $seen[$url] = true; ?>
<figure><img src="<?= $e($url) ?>" alt="<?= $e($photo['caption'] ?? $r['title']) ?>" loading="lazy"><?php if (!empty($photo['caption'])): ?><figcaption><?= $e($photo['caption']) ?></figcaption><?php endif ?></figure>
<?php endforeach ?></div>
<?php foreach ($r['tags'] ?? [] as $tag): ?><span class="chip"><?= $e($tag['label']) ?></span><?php endforeach ?>
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
<?php foreach ($r['actions'] ?? [] as $action): if ($action['step_id'] !== $step['id']) continue; $parts=[]; if (isset($action['label'])) $parts[]=$action['label']; if (isset($action['duration_seconds'])) $parts[]=$action['duration_seconds'].' seconds'; if (isset($action['temperature_value'])) $parts[]=$number($action['temperature_value']).'°'.$action['temperature_unit']; ?><span class="chip"><?= $e(implode(' · ', $parts ?: [$action['action_type']])) ?></span><?php endforeach ?>
</div><?php endforeach ?>
<?php endif ?>
<?php if (isset($r['notes'])): ?><h2>Notes</h2><p><?= $e($r['notes']) ?></p><?php endif ?>
<?php $n=$r['nutrition'] ?? null; if ($n): ?><h2>Nutrition</h2><p><?= ($n['is_estimate'] ?? 0) == 1 ? 'Estimated' : 'Reported' ?> · <?= ($n['basis'] ?? '') === 'per_serving' ? 'Per serving' : 'Whole recipe' ?></p>
<?php if ($n['stale'] ?? false): ?><p class="warning">This estimate needs recalculation after recipe changes.</p><?php endif ?>
<div class="nutrition"><?php foreach (['calories_kcal'=>'Calories (kcal)','protein_g'=>'Protein (g)','carbohydrate_g'=>'Carbohydrates (g)','fat_g'=>'Fat (g)','saturated_fat_g'=>'Saturated fat (g)','fiber_g'=>'Fiber (g)','sugar_g'=>'Sugar (g)','sodium_mg'=>'Sodium (mg)'] as $key=>$label): if (isset($n[$key])): ?><span><?= $e($label) ?>: <?= $e($number($n[$key])) ?></span><?php endif; endforeach ?></div><p>Method: <?= $e($n['method'] ?? '') ?></p><?php endif ?>
<?php foreach (['related'=>'Recipe family','pairings'=>'Serve alongside'] as $key=>$heading): if (!empty($r[$key])): ?><h2><?= $heading ?></h2><?php foreach ($r[$key] as $row): ?><p><?= $e($row['title']) ?><?php if ($key === 'related'): ?><br><?= $row['id'] === ($r['parent_recipe_id'] ?? null) ? 'Parent recipe' : 'Variation' ?><?php endif ?></p><?php endforeach; endif; endforeach ?>
<?php $source=array_filter(array_map(static fn($key)=>$r[$key] ?? null, ['source_title','source_author','source_url','source_reference']), static fn($v)=>$v!==null); if ($source): ?><h2>Source</h2><?php foreach ($source as $value): ?><p class="source"><?= $e($value) ?></p><?php endforeach; endif ?>
</main></body></html>
<?php return ob_get_clean();
}
