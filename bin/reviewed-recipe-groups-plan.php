<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

function reviewedRecipeGroupsPlan(): array {
    return [
        40=>['revision'=>2,'count'=>9,'fingerprint'=>'61b531584eb0df0c263d856356b63bc4c72d876a4d96c5e75f7cfb13b16a0676','groups'=>['Fruit filling'=>[1,2,3,4],'Topping'=>[5,6,7,8,9]]],
        14=>['revision'=>5,'count'=>17,'fingerprint'=>'b4b48ae614430ec3789b52bd3482cd12d1df9e2726acaad989e7d2e1a35f2699','groups'=>['Chicken & coating'=>[1,2,3,4,5,6,16,17],'Orange sauce'=>[7,8,9,10,11,12,13,14,15]]],
        37=>['revision'=>2,'count'=>17,'fingerprint'=>'b3110796a40647af780cd9e52765566b6047e6e80b61f5ef1a9b14673ac0c5b6','groups'=>['Chicken & coating'=>[1,2,3,4,5,6,16,17],'Honey-orange sauce'=>[7,8,9,10,11,12,13,14,15]]],
        11=>['revision'=>5,'count'=>16,'fingerprint'=>'9b3772b5536ddaed452fa696c18def1fa487541c78d2ffe181cde15d61b82c37','groups'=>['Cake'=>[1,2,3,4,5,6,7,8,9,10],'Frosting'=>[11,12,13,14,15,16]]],
        28=>['revision'=>4,'count'=>19,'fingerprint'=>'2b8681b817a5abe7e382bebf530463375040eea38844547d2875545ca67cd10c','groups'=>['Stock'=>[1,2,3,4,5,6,7,8,9,10,11],'Soup'=>[12,13,14,15,16,17,18,19]]],
        10=>['revision'=>5,'count'=>12,'fingerprint'=>'430fafaa6b2de88d3626013618cfcf9820959c99fb9254ba9f27de3d1373fb16','groups'=>['Cake batter'=>[1,2,3,4,5,6,7,8],'Fudge mixture'=>[9,10,11,12]]],
        43=>['revision'=>2,'count'=>8,'fingerprint'=>'436d0e29c5ede3f6ed6746718de9fb2ca7cb60687bb6d4951019f0b586c3c707','groups'=>['Cookie crust'=>[1,2],'Chocolate sauce'=>[3,4,5,6]]],
        24=>['revision'=>3,'count'=>12,'fingerprint'=>'12dad901ce6b47083cdf7ff2a9e8c3b787c69dc7ace947995d0667e68e51743c','groups'=>['Lime filling'=>[1,2,3,4,5,6,7,8,9],'Meringue'=>[11,12,13]]],
        18=>['revision'=>5,'count'=>4,'fingerprint'=>'9693308892df4c33f21c61508451a7448f51cb6d63ebf11a2253e219c52a8734','groups'=>['Mint syrup'=>[1,2,3],'Cocktail'=>[4,5,6,7]]],
    ];
}
