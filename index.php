<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

try {
    $config = configuration();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $config['allowed_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, If-Match');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $prefix = rtrim($config['base_path'], '/') . '/api/v1';
    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
        respond(404, ['error' => ['code' => 'not_found', 'message' => 'Endpoint not found.']]);
        exit;
    }
    $route = substr($path, strlen($prefix));
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'OPTIONS') {
        http_response_code(204);
    } elseif ($route === '/health') {
        if ($method !== 'GET') {
            header('Allow: GET, OPTIONS');
            respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']]);
        } else {
            respond(200, ['status' => 'ok', 'service' => 'Coffey Table Recipes', 'api_version' => 1]);
        }
    } elseif (preg_match('#^/recipes/([1-9][0-9]{0,18})$#', $route, $publicRecipe)) {
        if ($method!=='GET') throw new HttpError(405,'method_not_allowed','Use GET.');
        $pdo=database($config);
        respond(200,['recipe'=>(new RecipeService($pdo,new HouseholdService($pdo)))->detail('','0',$publicRecipe[1],true)]);    } elseif (in_array($route, ['/me/timer-devices', '/me/timer-devices/remove', '/me/timers'], true)) {
        if (!in_array($method, ['GET','POST'], true) || ($route !== '/me/timers' && $method !== 'POST')) throw new HttpError(405,'method_not_allowed','Unsupported timer request.');
        $identity=authenticatedIdentity($config); $pdo=database($config);
        $directory=new FirebaseAccountDirectory($config);
        $user=(new HouseholdService($pdo,[$directory,'requireAccount']))->bootstrap($identity);
        $service=new TimerSyncService($pdo,[new TimerPush($config),'send']);
        if ($route === '/me/timer-devices') $result=$service->device($user['id'],requestBody());
        elseif ($route === '/me/timer-devices/remove') $result=$service->unregister($user['id'],requestBody());
        elseif ($method === 'GET') $result=$service->list($user['id']);
        else $result=$service->change($user['id'],$identity['uid'],requestBody());
        respond(200,$result);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/shopping-list$#', $route, $shoppingMatch)) {
        if (!in_array($method, ['GET', 'POST'], true)) throw new HttpError(405, 'method_not_allowed', 'Use GET or POST.');
        $identity = authenticatedIdentity($config); $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $service = new ShoppingListService($pdo, $households);
        respond(200, $method === 'GET' ? $service->read($user['id'], $shoppingMatch[1]) : $service->save($user['id'], $shoppingMatch[1], requestBody(524288)));
    } elseif ($route === '/me/verification-email') {
        if ($method !== 'POST') throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        $identity = authenticatedIdentity($config);
        requestBody();
        $directory = new FirebaseAccountDirectory($config);
        (new VerificationService([$directory, 'verificationLink'], [new VerificationMail($config), 'send']))->send($identity);
        respond(200, ['sent' => true]);
    } elseif ($route === '/admin/parent-categories' || preg_match('#^/admin/parent-categories/([1-9][0-9]{0,18})/category-image$#',$route,$parentImageMatch)) {
        if (!in_array($method,['GET','POST'],true)) throw new HttpError(405,'method_not_allowed','Use GET or POST.');
        $identity=authenticatedIdentity($config); $pdo=database($config);
        $directory=new FirebaseAccountDirectory($config);
        $user=(new HouseholdService($pdo,[$directory,'requireAccount']))->bootstrap($identity);
        $mediaUrl=rtrim(preg_replace('#/app/?$#','',$config['app_url']),'/').'/media/recipes';
        $images=new CategoryImageService($pdo,new RecipePhotoService($config['recipe_ai'],__DIR__.'/storage'),__DIR__.'/media/recipes',$mediaUrl);
        $admin=new AdministrationService($pdo,[$directory,'delete'],null,$images,$mediaUrl);
        if (isset($parentImageMatch[1])) {
            if ($method!=='POST') throw new HttpError(405,'method_not_allowed','Use POST.');
            requestBody();
            respond(200,['image_url'=>$images->ensureParent($parentImageMatch[1],$user['id'])]);
        } else {
            respond(200,$admin->parentCategories($user['id'],$method==='POST'?requestBody():null));
        }
    } elseif ($route === '/admin/households' || preg_match('#^/admin/households/([1-9][0-9]{0,18})/ai-images$#', $route, $permissionMatch)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $directory = new FirebaseAccountDirectory($config);
        $user = (new HouseholdService($pdo, [$directory, 'requireAccount']))->bootstrap($identity);
        $service = new AdministrationService($pdo, [$directory, 'delete']);
        if ($route === '/admin/households' && $method === 'GET') {
            respond(200, ['households' => $service->households($user['id'])]);
        } elseif (isset($permissionMatch[1]) && $method === 'POST') {
            respond(200, ['household' => $service->setAiImages($user['id'], $permissionMatch[1], requestBody()['enabled'] ?? null)]);
        } else {
            throw new HttpError(405, 'method_not_allowed', 'Unsupported request method.');
        }
    } elseif ($route === '/admin/users'  || preg_match('#^/admin/(users|people|firebase)/([A-Za-z0-9_-]{1,128})/delete$#', $route, $adminMatch)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $directory = new FirebaseAccountDirectory($config);
        $user = (new HouseholdService($pdo, [$directory, 'requireAccount']))->bootstrap($identity);
        $service = new AdministrationService($pdo, [$directory, 'delete'], [$directory, 'all']);
        if ($route === '/admin/users' && $method === 'GET') {
            respond(200, ['users' => $service->users($user['id']), 'people' => $service->people($user['id']), 'households' => $service->households($user['id'])]);
        } elseif (isset($adminMatch[1]) && $method === 'POST') {
            $body = requestBody();
            if ($adminMatch[1] === 'people') {
                $service->deletePerson($user['id'], $adminMatch[2], ($body['confirm'] ?? false) === true);
            } elseif ($adminMatch[1] === 'firebase') {
                $service->deleteFirebaseOnly($user['id'], $adminMatch[2], ($body['confirm'] ?? false) === true);
            } else {
                $service->delete($user['id'], $adminMatch[2], ($body['confirm'] ?? false) === true);
            }
            respond(200, ['deleted' => true]);
        } else {
            throw new HttpError(405, 'method_not_allowed', 'Unsupported request method.');
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/([1-9][0-9]{0,18})/edit-options$#', $route, $editOptionsMatch)) {
        if ($method!=='GET') throw new HttpError(405,'method_not_allowed','Use GET.');
        $identity=authenticatedIdentity($config); $pdo=database($config);
        $households=new HouseholdService($pdo,[new FirebaseAccountDirectory($config),'requireAccount']);
        $user=$households->bootstrap($identity);
        $recipe=(new RecipeService($pdo,$households))->detail($user['id'],$editOptionsMatch[1],$editOptionsMatch[2]);
        $options=$recipe['edit_options'];
        $options['ai_images_enabled']=$households->aiImagesEnabled($user['id'],$editOptionsMatch[1]);
        if ($recipe['source_type']==='blank') $options['favorites']=(new RecipeService($pdo,$households))->favorites($user['id'],$editOptionsMatch[1],$editOptionsMatch[2]);
        respond(200,['edit_options'=>$options]);    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/([1-9][0-9]{0,18})/share$#', $route, $shareMatch)) {
        if ($method !== 'GET') throw new HttpError(405, 'method_not_allowed', 'Use GET.');
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $recipe = (new RecipeService($pdo, $households))->detail($user['id'], $shareMatch[1], $shareMatch[2]);
        if ($recipe['source_type'] === 'blank') throw new HttpError(422, 'blank_recipe', 'Import this recipe before sharing it.');
        $root = rtrim(preg_replace('#/app/?$#', '', $config['app_url']), '/');
        respond(200, ['url' => $root . '/share.php?id=' . $shareMatch[2]]);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/category-image$#', $route, $categoryMatch)) {
        if ($method !== 'POST') throw new HttpError(405,'method_not_allowed','Use POST.');
        $identity=authenticatedIdentity($config); $pdo=database($config);
        $households=new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']); $user=$households->bootstrap($identity);
        $households->membership($user['id'],$categoryMatch[1]);
        $body=requestBody(); $id=$body['category_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/',$id)) throw new HttpError(422,'invalid_category','Choose a category.');
        $mediaUrl=rtrim(preg_replace('#/app/?$#','',$config['app_url']),'/').'/media/recipes';
        $images=new CategoryImageService($pdo,new RecipePhotoService($config['recipe_ai'],__DIR__.'/storage'),__DIR__.'/media/recipes',$mediaUrl);
        respond(200,['image_url'=>$images->ensure($id,$user['id'],$categoryMatch[1])]);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipe-photo$#', $route, $photoMatch)) {
        if ($method !== 'POST') throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        $identity = authenticatedIdentity($config); $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $households->requireAiImages($user['id'], $photoMatch[1]);
        $body = requestBody(100000); $recipe = $body['recipe'] ?? null;
        if (!is_array($recipe)) throw new HttpError(422,'invalid_recipe','Choose a recipe.');
        if (isset($recipe['id'])) {
            if (!is_string($recipe['id']) || !preg_match('/^[1-9][0-9]{0,18}$/',$recipe['id'])) throw new HttpError(422,'invalid_recipe','Invalid recipe.');
            $stored = (new RecipeService($pdo,$households))->detail($user['id'],$photoMatch[1],$recipe['id']);
            if ($stored['photos']!==[]) throw new HttpError(409,'photo_exists','This recipe already has photos.');
        }
        respond(200,(new RecipePhotoService($config['recipe_ai'],__DIR__.'/storage'))->generate($recipe,$user['id'],$photoMatch[1]));
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/([1-9][0-9]{0,18})/variation$#', $route, $variationMatch)) {
        if ($method !== 'POST') throw new HttpError(405,'method_not_allowed','Use POST.');
        $identity = authenticatedIdentity($config); $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $households->membership($user['id'],$variationMatch[1]);
        $policy = RecipeVariationService::accessPolicy($config['recipe_ai']);
        if ($policy === 'ai_images') {
            try { $households->requireAiImages($user['id'],$variationMatch[1]); }
            catch (HttpError $error) {
                if ($error->errorCode !== 'ai_images_disabled') throw $error;
                throw new HttpError(403,'variations_disabled','AI recipe variations are disabled for this household. Ask the application administrator to enable them.');
            }
        }
        $recipes = new RecipeService($pdo,$households);
        $source = $recipes->detail($user['id'],$variationMatch[1],$variationMatch[2]);
        $options = $recipes->editOptions($user['id'],$variationMatch[1]);
        $draft = (new RecipeVariationService($config['recipe_ai'],__DIR__.'/storage'))->analyze($source,requestBody(100000),$user['id'],$variationMatch[1],$options['categories']);
        $draft['recipe']['edit_options'] = $options;
        respond(200,$draft);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipe-blank$#', $route, $blankMatch)) {
        if ($method !== 'POST') throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        $identity = authenticatedIdentity($config); $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $options = (new RecipeService($pdo, $households))->editOptions($user['id'], $blankMatch[1]);
        $options['ai_images_enabled'] = false;
        $options['favorites'] = (new RecipeService($pdo,$households))->newRecipeFavorites($user['id'],$blankMatch[1]);
        respond(200, ['recipe'=>['title'=>'','source_type'=>'blank','original_language'=>'en','measurement_system'=>'unspecified','edit_options'=>$options]]);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipe-import$#', $route, $importMatch)) {
        if ($method !== 'POST') throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $households->membership($user['id'], $importMatch[1]);
        $options = (new RecipeService($pdo, $households))->editOptions($user['id'], $importMatch[1]);
        $draft = (new RecipeImportService($config['recipe_ai'], __DIR__.'/storage'))->analyze(requestBody(8*1024*1024), $user['id'], $options['categories']);
        $draft['recipe']['edit_options'] = $options;
        foreach ($draft['related_imports'] ?? [] as $index=>$related) $draft['related_imports'][$index]['recipe']['edit_options']=$options;
        respond(200, $draft);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/(?:deleted|([1-9][0-9]{0,18})/(delete|restore))$#', $route, $deletionMatch)) {
        $listing = !isset($deletionMatch[2]) || $deletionMatch[2] === '';
        if ($method !== ($listing ? 'GET' : 'POST')) throw new HttpError(405, 'method_not_allowed', $listing ? 'Use GET.' : 'Use POST.');
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $deletion = new RecipeDeletionService($pdo, $households);
        if ($listing) {
            $offset = $_GET['offset'] ?? '0';
            if (!is_string($offset) || !preg_match('/^[0-9]{1,6}$/', $offset)) throw new HttpError(400, 'invalid_filter', 'Invalid offset.');
            respond(200, $deletion->recentlyDeleted($user['id'], $deletionMatch[1], (int)$offset));
        } else {
            $body = requestBody();
            if ($deletionMatch[3] === 'delete') respond(200, $deletion->delete($user['id'], $deletionMatch[1], $deletionMatch[2], $body));
            else respond(200, $deletion->restore($user['id'], $deletionMatch[1], $deletionMatch[2]));
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/([1-9][0-9]{0,18})/attach$#', $route, $attachMatch)) {
        if ($method!=='POST') throw new HttpError(405,'method_not_allowed','Use POST.');
        $identity=authenticatedIdentity($config); $pdo=database($config);
        $households=new HouseholdService($pdo,[new FirebaseAccountDirectory($config),'requireAccount']);
        $user=$households->bootstrap($identity); requestBody();
        respond(200,(new RecipeMembership($pdo,$households))->attach($user['id'],$attachMatch[1],$attachMatch[2]));
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes/([1-9][0-9]{0,18})/favorites$#',$route,$favoriteMatch)) {
        $pdo=database($config);
        $households=new HouseholdService($pdo,[new FirebaseAccountDirectory($config),'requireAccount']);
        $user=$households->bootstrap(authenticatedIdentity($config));
        $service=new RecipeService($pdo,$households);
        if ($method==='GET') {
            respond(200,$service->favorites($user['id'],$favoriteMatch[1],$favoriteMatch[2]));
        } elseif ($method==='POST') {
            respond(200,$service->setFavorite($user['id'],$favoriteMatch[1],$favoriteMatch[2],requestBody()));
        } else {
            throw new HttpError(405,'method_not_allowed','Use GET or POST.');
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes(?:/([1-9][0-9]{0,18}))?$#', $route, $recipeMatch)) {
        $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $recipes = new RecipeService($pdo, $households);
        if ($method === 'POST') {
            $user = $households->bootstrap(authenticatedIdentity($config));
            $households->membership($user['id'], $recipeMatch[1]);
            $mediaUrl = rtrim(preg_replace('#/app/?$#', '', $config['app_url']), '/') . '/media/recipes';
            $classifier = new BlankRecipeCategoryService($config['recipe_ai'],__DIR__.'/storage');
            $editor = new RecipeEditorService($pdo, $households, __DIR__.'/media/recipes', $mediaUrl, [$classifier,'categorize']);
            respond(isset($recipeMatch[2]) ? 200 : 201, ['recipe' => $editor->save($user['id'], $recipeMatch[1], $recipeMatch[2] ?? '', requestBody(30*1024*1024))]);
        } elseif ($method !== 'GET') {
            header('Allow: GET, POST, OPTIONS');
            throw new HttpError(405, 'method_not_allowed', 'Use GET or POST for a recipe.');
        } elseif (isset($recipeMatch[2])) {
            respond(200, ['recipe' => $recipes->detail('', $recipeMatch[1], $recipeMatch[2], true)]);
        } else {
            $query = $_GET['q'] ?? '';
            $offset = $_GET['offset'] ?? '0';
            $category = $_GET['category'] ?? '';
            $scope = $_GET['scope'] ?? '';
            $tree = $_GET['tree'] ?? '0';
            $families = $_GET['families'] ?? '0';
            if (!is_string($families) || !in_array($families,['0','1'],true)) throw new HttpError(400,'invalid_filter','Invalid recipe family filter.');
            if (!is_string($query) || !is_string($offset) || !preg_match('/^[0-9]{1,6}$/', $offset)) throw new HttpError(400, 'invalid_filter', 'Invalid recipe search.');
            if (!is_string($category)) throw new HttpError(400, 'invalid_filter', 'Invalid recipe category.');
            if (!is_string($scope) || !is_string($tree) || !in_array($tree, ['0', '1'], true)) throw new HttpError(400, 'invalid_filter', 'Invalid category scope.');
            $blank=$_GET['blank'] ?? '0';
            if (!is_string($blank) || !in_array($blank,['0','1'],true)) throw new HttpError(400,'invalid_filter','Invalid blank recipe filter.');
            $favoritePeople=$_GET['favorite_people'] ?? '';
            if (!is_string($favoritePeople)) throw new HttpError(400,'invalid_filter','Invalid favorite filter.');
            $filterUser=$favoritePeople!=='' ? $households->bootstrap(authenticatedIdentity($config))['id'] : '';
            respond(200, $recipes->browse($filterUser, $recipeMatch[1], trim($query), (int) $offset, trim($category), $scope, $tree === '1', $families === '1', true, $favoritePeople, $blank === '1'));
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/people/([1-9][0-9]{0,18})/invitations$#', $route, $inviteMatch)
        || preg_match('#^/invitations/([1-9][0-9]{0,18})/respond$#', $route, $respondMatch)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        }
        $invitations = invitationService($pdo, $households, $config);
        if (isset($inviteMatch[1])) {
            $body = requestBody();
            if (!is_string($body['email'] ?? null)) throw new HttpError(422, 'invalid_email', 'Enter a valid email address.');
            respond(201, ['invitation' => $invitations->invite($user, $inviteMatch[1], $inviteMatch[2], $body['email'])]);
        } else {
            $body = requestBody();
            if (!is_string($body['decision'] ?? null)) throw new HttpError(422, 'invalid_decision', 'Choose accept or decline.');
            respond(200, ['invitation' => $invitations->respond($user, $respondMatch[1], $body['decision'])]);
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/people/me/name$#', $route, $personNameMatch)) {
        $identity = authenticatedIdentity($config);
        $service = new HouseholdService(database($config), [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $service->bootstrap($identity);
        $service->membership($user['id'], $personNameMatch[1]);
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        }
        respond(200, ['person' => $service->renameOwnPerson($user['id'], $personNameMatch[1], requiredName(requestBody(), 'display_name'))]);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/name$#', $route, $nameMatch)) {
        $identity = authenticatedIdentity($config);
        $service = new HouseholdService(database($config), [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $service->bootstrap($identity);
        $service->membership($user['id'], $nameMatch[1], true);
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            throw new HttpError(405, 'method_not_allowed', 'Use POST.');
        }
        respond(200, ['household' => $service->rename($user['id'], $nameMatch[1], requiredName(requestBody(), 'name'))]);
    } elseif ($route === '/me' || $route === '/households' || preg_match('#^/households/([1-9][0-9]{0,18})/people$#', $route, $match)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $service = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $service->bootstrap($identity);
        if ($route === '/me' && $method === 'GET') {
            $pending = invitationService($pdo, $service, $config)->pending($user);
            respond(200, ['user' => $user, 'households' => $service->households($user['id']),
                'invitations' => $pending['invitations'], 'invitation_verification_required' => $pending['verification_required']]);
        } elseif ($route === '/households' && $method === 'GET') {
            respond(200, ['households' => $service->households($user['id'])]);
        } elseif ($route === '/households' && $method === 'POST') {
            $body = requestBody();
            respond(201, ['household' => $service->create($user['id'], requiredName($body, 'name'), requiredName($body, 'person_name'))]);
        } elseif (isset($match[1]) && $method === 'GET') {
            respond(200, ['people' => $service->people($user['id'], $match[1])]);
        } elseif (isset($match[1]) && $method === 'POST') {
            $service->membership($user['id'], $match[1], true);
            respond(201, ['person' => $service->addPerson($user['id'], $match[1], requiredName(requestBody(), 'display_name'))]);
        } else {
            header('Allow: ' . ($route === '/me' ? 'GET, OPTIONS' : 'GET, POST, OPTIONS'));
            respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Unsupported request method.']]);
        }
    } else {
        respond(404, ['error' => ['code' => 'not_found', 'message' => 'Endpoint not found.']]);
    }
} catch (HttpError $error) {
    if ($error->status === 401) header('WWW-Authenticate: Bearer');
    respond($error->status, ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']]);
}
