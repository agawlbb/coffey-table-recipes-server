INSERT INTO recipe_categories (parent_id,slug,label,image_key,position)
SELECT p.id, s.slug, s.label, s.image_key, s.position FROM recipe_categories p
JOIN (
    SELECT 'main-dishes' AS parent_slug,'main-chinese' AS slug,'Chinese' AS label,'chinese' AS image_key,1 AS position
    UNION ALL SELECT 'main-dishes','main-seafood','Seafood','seafood',2
    UNION ALL SELECT 'main-dishes','main-casseroles','Casseroles','casseroles',3
    UNION ALL SELECT 'main-dishes','main-pizza','Pizza','pizza',4
    UNION ALL SELECT 'side-dishes','side-breads','Breads','breads',1
    UNION ALL SELECT 'side-dishes','side-stuffing','Stuffing','stuffing',2
    UNION ALL SELECT 'desserts','dessert-cakes','Cakes','cakes',1
    UNION ALL SELECT 'desserts','dessert-ice-cream','Ice cream','ice-cream',2
    UNION ALL SELECT 'drinks','drink-cocktails','Cocktails','cocktails',1
    UNION ALL SELECT 'breakfast','breakfast-muffins','Muffins','muffins',1
    UNION ALL SELECT 'appetizers','appetizer-dips','Dips','dips',1
    UNION ALL SELECT 'appetizers','appetizer-bbq','BBQ','bbq',2
) s ON p.slug=s.parent_slug;
