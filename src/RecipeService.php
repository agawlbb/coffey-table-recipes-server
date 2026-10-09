<?php
declare(strict_types=1);

final class RecipeService
{
    public function __construct(private PDO $pdo, private HouseholdService $households) {}

    private function rows(string $sql, array $parameters): array {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return array_map(static function(array $row): array {
            foreach ($row as $key => $value) {
                if (($key === 'id' || str_ends_with($key, '_id') || $key === 'revision' || str_ends_with($key, '_revision')) && $value !== null) $row[$key] = (string) $value;
            }
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function browse(string $userId, string $householdId, string $query = '', int $offset = 0, string $category = '', string $scope = '', bool $tree = false): array {
        $this->households->membership($userId, $householdId);
        if (mb_strlen($query) > 200 || mb_strlen($category) > 120 || $offset < 0 || $offset > 100000) throw new HttpError(400, 'invalid_filter', 'Invalid recipe search.');
        $parameters = [$householdId];
        $where = 'r.household_id = ?';
        $node = null;
        if ($scope !== '') {
            if (!preg_match('/^[1-9][0-9]{0,18}$/', $scope)) throw new HttpError(400, 'invalid_filter', 'Invalid category scope.');
            $node = $this->rows('SELECT id,parent_id,label FROM recipe_categories WHERE id=?', [$scope])[0] ?? null;
            if (!$node) throw new HttpError(404, 'category_not_found', 'Category not found.');
            $where .= ' AND EXISTS (SELECT 1 FROM recipe_category_assignments a JOIN recipe_categories c ON c.id=a.category_id WHERE a.household_id=r.household_id AND a.recipe_id=r.id AND (c.id=? OR c.parent_id=?))';
            array_push($parameters, $scope, $scope);
        }
        if ($tree && $query === '' && ($node === null || $node['parent_id'] === null)) {
            $nodes = $this->rows('SELECT c.id,c.parent_id,c.slug,c.label,c.image_key,COUNT(DISTINCT a.recipe_id) AS recipe_count
                FROM recipe_categories c LEFT JOIN recipe_categories child ON child.parent_id=c.id
                LEFT JOIN recipe_category_assignments a ON (a.category_id=c.id OR a.category_id=child.id) AND a.household_id=?
                WHERE c.parent_id <=> ? GROUP BY c.id,c.parent_id,c.slug,c.label,c.image_key,c.position
                HAVING c.parent_id IS NULL OR COUNT(DISTINCT a.recipe_id)>0 ORDER BY c.position,c.label,c.id', [$householdId, $scope === '' ? null : $scope]);
            return ['recipes' => [], 'category_nodes' => $nodes, 'view' => 'categories', 'next_offset' => null];
        }
        if ($category !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM recipe_tags c WHERE c.recipe_id=r.id AND c.kind='category' AND c.label=?)";
            $parameters[] = $category;
        }
        if ($query !== '') {
            // Escape LIKE wildcards so searches are literal, not user-supplied patterns.
            $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (r.title LIKE ? ESCAPE '!' OR r.description LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM recipe_ingredients i WHERE i.recipe_id=r.id AND i.ingredient_name LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM recipe_tags t WHERE t.recipe_id=r.id AND t.label LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM recipe_category_assignments a JOIN recipe_categories c ON c.id=a.category_id LEFT JOIN recipe_categories p ON p.id=c.parent_id WHERE a.household_id=r.household_id AND a.recipe_id=r.id AND (c.label LIKE ? ESCAPE '!' OR p.label LIKE ? ESCAPE '!')))";
            array_push($parameters, $like, $like, $like, $like, $like, $like);
        }
        $items = $this->rows("SELECT r.id,r.title,r.description,r.original_language,r.servings,r.yield_text,r.prep_minutes,r.cook_minutes,r.total_minutes,r.parent_recipe_id,
            (SELECT source_url FROM recipe_photos p WHERE p.recipe_id=r.id ORDER BY p.is_cover DESC,p.position LIMIT 1) AS photo_url
            FROM recipes r WHERE $where ORDER BY r.title,r.id LIMIT 31 OFFSET $offset", $parameters);
        $hasMore = count($items) > 30;
        if ($hasMore) array_pop($items);
        $categories = $this->rows("SELECT DISTINCT label FROM recipe_tags WHERE household_id=? AND kind='category' ORDER BY label", [$householdId]);
        return ['recipes' => $items, 'categories' => array_column($categories, 'label'), 'category_nodes' => [], 'view' => 'recipes', 'next_offset' => $hasMore ? $offset + count($items) : null];
    }

    public function detail(string $userId, string $householdId, string $recipeId): array {
        $this->households->membership($userId, $householdId);
        $rows = $this->rows('SELECT * FROM recipes WHERE household_id=? AND id=?', [$householdId, $recipeId]);
        if (!$rows) throw new HttpError(404, 'recipe_not_found', 'Recipe not found.');
        $recipe = $rows[0];
        // Keep raw extracted source text and import bookkeeping out of the display response.
        unset($recipe['source_text'], $recipe['import_key']);
        foreach (['ingredients' => 'recipe_ingredients', 'groups' => 'recipe_ingredient_groups', 'steps' => 'recipe_steps', 'photos' => 'recipe_photos'] as $key => $table) {
            $recipe[$key] = $this->rows("SELECT * FROM $table WHERE household_id=? AND recipe_id=? ORDER BY position,id", [$householdId, $recipeId]);
        }
        $recipe['actions'] = $this->rows('SELECT * FROM recipe_step_actions WHERE household_id=? AND recipe_id=? ORDER BY step_id,position,id', [$householdId, $recipeId]);
        $recipe['tags'] = $this->rows('SELECT kind,label FROM recipe_tags WHERE household_id=? AND recipe_id=? ORDER BY kind,label', [$householdId, $recipeId]);
        $nutrition = $this->rows('SELECT * FROM recipe_nutrition WHERE household_id=? AND recipe_id=?', [$householdId, $recipeId]);
        $recipe['nutrition'] = $nutrition[0] ?? null;
        if ($recipe['nutrition'] !== null) $recipe['nutrition']['stale'] = $recipe['nutrition']['recipe_revision'] !== $recipe['revision'];
        $parent = $recipe['parent_recipe_id'];
        $recipe['related'] = $this->rows('SELECT id,title,parent_recipe_id FROM recipes WHERE household_id=? AND id<>? AND (parent_recipe_id=? OR id=? OR (? IS NOT NULL AND parent_recipe_id=?)) ORDER BY title,id', [$householdId, $recipeId, $parent ?? $recipeId, $parent, $parent, $parent]);
        $recipe['pairings'] = $this->rows('SELECT r.id,r.title FROM recipe_pairings p JOIN recipes r ON r.id=CASE WHEN p.recipe_id=? THEN p.paired_recipe_id ELSE p.recipe_id END AND r.household_id=p.household_id WHERE p.household_id=? AND (p.recipe_id=? OR p.paired_recipe_id=?) ORDER BY r.title,r.id', [$recipeId, $householdId, $recipeId, $recipeId]);
        return $recipe;
    }
}
