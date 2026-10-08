ALTER TABLE recipe_ingredients
    DROP FOREIGN KEY recipe_ingredients_ibfk_2,
    ADD CONSTRAINT recipe_ingredient_group_fk FOREIGN KEY (household_id, recipe_id, group_id)
        REFERENCES recipe_ingredient_groups(household_id, recipe_id, id) ON DELETE CASCADE;
