# Recipe favorites

Migration 013 stores favorites per recipe, household and household person, including people without login accounts. Any authenticated household member can set favorites for people in that household. Other households cannot read or change those favorites. Detached/deleted recipes cannot be changed.

GET /households/{household}/recipes/{recipe}/favorites returns people with favorite flags and current_person_id. POST accepts person_id and a boolean favorite; omitted person_id selects the caller. Writes are idempotent.

Browsing accepts favorite_people as comma-separated household person IDs. Every selected person must favorite the same recipe. This requires authentication, applies before pagination, respects category/search scope, and returns exact recipes/variations rather than an unmatching family representative. No selection preserves normal browsing. Favorite names are never added to public recipe responses.

Deployment runs the dedicated CLI migration helper before publishing dependent PHP. It uses the existing protected full backup, exact target verification, migration checksum, temporary schema privileges and restoration workflow. No frontend deployment is included.

Validation: PHP favorites fixtures cover self and other-person changes, idempotence, household isolation, deleted recipes, invalid values and all-selected filtering. Existing sharing and variation relationship fixtures also pass.
