# Recipe category browsing

The hosted database on coffeybean.com uses two category levels. Migration 005
adds `recipe_categories` (parent links, stable slugs, labels, image keys, ordering)
and `recipe_category_assignments` (household, recipe, category). Migration 006
seeds the initial subcategory catalog. Assignments can place one recipe in several
appropriate subcategories without duplicating the recipe or its original tags.
The household/recipe foreign key prevents assigning another household's recipe.

`GET /households/{id}/recipes?tree=1` returns the six main categories.
Adding `scope={main-category-id}` returns populated subcategories for that
household. A subcategory scope returns recipes. Adding a nonempty `q` searches
recipes within the selected scope; omitting scope searches all accessible
household recipes. Search matches title, description, ingredients, tags, and
category labels. Results paginate at 30 recipes using `next_offset`.

Category responses contain `view: categories`, `category_nodes` with `id`,
`parent_id`, `slug`, `label`, `image_key`, `recipe_count`, an empty `recipes`
array, and a null `next_offset`. Recipe responses contain `view: recipes` and
the existing recipe summaries. Parent counts use distinct recipe IDs. Empty
main categories remain visible; empty subcategories are hidden. Authentication
and household membership apply to every request. Older clients that omit
`tree` retain the flat recipe list and legacy `category` tag filter.

The app bundles 384px AI-generated illustrations matching `image_key`; it
does not send image-generation requests during browsing. Add matching artwork
to the app when adding a new catalog entry. The catalog is currently maintained
through reviewed migrations/administrative SQL rather than an editing API.
For future imports, review each recipe and insert its household, recipe ID and
leaf category ID into `recipe_category_assignments`. Existing import tags alone
do not create hierarchy assignments. Avoid assigning recipes directly to roots
or adding more levels until the browser is extended to support them.

## Hosted classification on 2026-10-09

The live inventory contained 13 recipes (IDs 9–21), all in household 2. Each was
reviewed using its title, ingredients, serving instructions and existing tags.
Sixteen assignments cover every recipe:

| Recipe | Category paths |
| --- | --- |
| Mint Chip Ice Cream | Desserts / Ice cream |
| Hot Fudge Chocolate Pudding Cake | Desserts / Cakes |
| Coffee-Chocolate Layer Cake with Mocha-Mascarpone Frosting | Desserts / Cakes |
| Homemade Hamburger Buns (Bread Machine) | Side dishes / Breads |
| Chinese buffet crab casserole recipe | Main dishes / Chinese; Seafood; Casseroles |
| Chinese Orange Chicken | Main dishes / Chinese |
| Bran Flax Muffins | Breakfast / Muffins |
| Smoked Queso | Appetizers / Dips; BBQ |
| Caramelized Onion and Cornbread Stuffing | Side dishes / Stuffing |
| Pitcher Cocktails: The Muddle-Free Mojito | Drinks / Cocktails |
| Cheesy Chicken and Potato Casserole | Main dishes / Casseroles |
| Crab Meat and Mushrooms in Wine Sauce | Main dishes / Seafood |
| Homemade Chicago-Style Deep Dish Pizza | Main dishes / Pizza |

Main counts: Main dishes 5, Side dishes 2, Desserts 3, Drinks 1, Breakfast 1,
Appetizers 1. The local application database was not migrated or categorized.
The user explicitly selected continued hosted development for this change.

The full hosted database export is retained in ignored local storage under
`category-deployment-20261009/hosted-before-categories.sql`. The hosted
`storage/backend-before-categories-20261009.zip` contains the previous deployed
entry point and service code. Deployment archives remain inside HTTP-protected
storage. Schema migration checksums were recorded in `schema_migrations`.
Assignments were added in a transaction guarded by the reviewed inventory's
IDs, exact titles, household and total count. Original recipe content was retained.

Validation: `php tests/recipe_schema.php` passes 55 checks in an automatically
created disposable database, including hierarchy counts, household isolation,
literal wildcard search, category-label search and scoped pagination.
The existing authentication/household suite passes 32 checks and recipe import
passes 44 checks. Live signed-in web browsing through the deployed API confirmed
main/leaf scope behavior and recipe details. Hosted health returns 200, localhost
CORS preflight returns 204, and source, migration and backup paths return 403.
