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
    if ($route === '/home-assistant/shopping-list' || preg_match('#^/(?:me/(?:timers|timer-devices(?:/remove)?)|recipes/[1-9][0-9]*|households/[1-9][0-9]*/(?:shopping-list(?:/sync-request)?|snapshot|name|people(?:/me/name)?|restaurants|recipe-blank|meal-plan(?:/.*)?|recipes(?:/(?![1-9][0-9]*/variation$).*)?))$#D', $route)) {
        throw new HttpError(410, 'data_moved', 'Data synchronization is available through the VPS.');
    }
    $childModes = new ChildMode(__DIR__.'/storage/child-mode');
    if (!ChildMode::allowed($route,$method) && !str_starts_with($route,'/home-assistant/')) {
        $childModes->guard(authenticatedIdentity($config),$route,$method);
    }
    if ($route === '/me/child-mode' && in_array($method,['GET','POST'],true)) {
        $identity=authenticatedIdentity($config);
        $h=new HouseholdService(database($config),[new FirebaseAccountDirectory($config),'requireAccount'],$childModes);
        $user=$h->bootstrap($identity);
        respond(200,$method==='GET'?$childModes->state($identity)+['people_child_modes'=>(object)$h->peopleChildModes($user['id'])]:$childModes->change($identity,requestBody()));
    } elseif (preg_match('#^/admin/users/([1-9][0-9]*)/reset-child-mode$#D',$route,$resetMatch) && $method==='POST') {
        $pdo=database($config);$identity=authenticatedIdentity($config);
        $h=new HouseholdService($pdo,[new FirebaseAccountDirectory($config),'requireAccount']);$actor=$h->bootstrap($identity);
        (new AdministrationService($pdo,static function(){}))->requireAdministrator($actor['id']);
        if ((requestBody()['confirm']??false)!==true) throw new HttpError(422,'confirmation_required','Confirm the child mode reset.');
        $q=$pdo->prepare('SELECT firebase_uid FROM users WHERE id=?');$q->execute([$resetMatch[1]]);$uid=$q->fetchColumn();
        if (!is_string($uid)) throw new HttpError(404,'user_not_found','User not found.');
        respond(200,$childModes->requestReset($uid));
    } elseif ($method === 'OPTIONS') {
        http_response_code(204);
    } elseif ($route === '/health') {
        if ($method !== 'GET') {
            header('Allow: GET, OPTIONS');
            respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']]);
        } else {
            respond(200, ['status' => 'ok', 'service' => 'Coffey Table Recipes', 'api_version' => 1]);
        }
    } elseif ($route === '/home-assistant/auth-config') {
        if ($method !== 'GET') throw new HttpError(405, 'method_not_allowed', 'Use GET.');
        $file = __DIR__ . '/config/home-assistant.local.json';
        $client = is_file($file) ? json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR) : [];
        if (empty($client['api_key'])) throw new HttpError(503, 'auth_unavailable', 'Home Assistant sign-in is not configured.');
        // Firebase web API keys identify the public project; they grant no household access.
        respond(200, ['api_key'=>$client['api_key']]);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/home-assistant-connections(?:/([a-f0-9]{64}))?$#', $route, $haMatch)) {
        if ((isset($haMatch[2]) && $method !== 'DELETE') || (!isset($haMatch[2]) && !in_array($method, ['GET','POST'], true))) throw new HttpError(405, 'method_not_allowed', 'Unsupported request.');
        if ($method === 'POST') requestBody();
        $identity = authenticatedIdentity($config); $pdo = database($config);
        $households = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount']);
        $user = $households->bootstrap($identity);
        $households->membership($user['id'], $haMatch[1], true);
        $connections = new HomeAssistantConnections($pdo);
        if ($method === 'DELETE') { $connections->revokeById($haMatch[1], $haMatch[2]); respond(200, ['revoked'=>true]); }
        elseif ($method === 'GET') respond(200, ['connections'=>$connections->list($haMatch[1])]);
        else respond(200, $connections->create($haMatch[1]));
    } elseif ($route === '/home-assistant/connection') {
        if ($method !== 'DELETE') throw new HttpError(405, 'method_not_allowed', 'Use DELETE.');
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer (ctrha_[a-f0-9]{64})$/D', $header, $haToken) ? $haToken[1] : '';
        $connections = new HomeAssistantConnections(database($config));
        $connections->household($token);
        $connections->revoke($token); respond(200, ['revoked'=>true]);
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
        // Keep the PHP execution budget above the bounded 75-second provider
        // request and below the app's 110-second deadline. Preserve unlimited
        // or already more generous hosting settings.
        $executionLimit = (int)ini_get('max_execution_time');
        if ($executionLimit > 0 && $executionLimit < 100) set_time_limit(100);
        $draft = (new RecipeVariationService($config['recipe_ai'],__DIR__.'/storage'))->analyze($source,requestBody(100000),$user['id'],$variationMatch[1],$options['categories']);
        $draft['recipe']['edit_options'] = $options;
        respond(200,$draft);
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipe-category$#D', $route, $categoryMatch)) {
        if ($method!=='POST') throw new HttpError(405,'method_not_allowed','Use POST.');
        $pdo=database($config); $households=new HouseholdService($pdo,[new FirebaseAccountDirectory($config),'requireAccount']);
        $user=$households->bootstrap(authenticatedIdentity($config));
        $households->membership($user['id'],$categoryMatch[1]);
        $body=requestBody(); $title=$body['title']??null;
        if (!is_string($title)||trim($title)===''||mb_strlen($title)>300) throw new HttpError(422,'invalid_recipe','Enter a recipe title.');
        $catalog=(new RecipeService($pdo,$households))->editOptions($user['id'],$categoryMatch[1])['categories'];
        respond(200,['category'=>(new BlankRecipeCategoryService($config['recipe_ai'],__DIR__.'/storage'))->categorize($title,$catalog,$user['id'])]);
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
    } elseif ($route === '/me' || $route === '/households') {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $service = new HouseholdService($pdo, [new FirebaseAccountDirectory($config), 'requireAccount'], $childModes);
        $user = $service->bootstrap($identity);
        if ($route === '/me' && $method === 'GET') {
            $user['child_mode']=$childModes->state($identity)['enabled'];
            $pending = invitationService($pdo, $service, $config)->pending($user);
            respond(200, ['user' => $user, 'households' => $service->households($user['id']),
                'invitations' => $pending['invitations'], 'invitation_verification_required' => $pending['verification_required']]);
        } elseif ($route === '/households' && $method === 'GET') {
            respond(200, ['households' => $service->households($user['id'])]);
        } elseif ($route === '/households' && $method === 'POST') {
            $body = requestBody();
            respond(201, ['household' => $service->create($user['id'], requiredName($body, 'name'), requiredName($body, 'person_name'))]);
        } else {
            header('Allow: ' . ($route === '/me' ? 'GET, OPTIONS' : 'GET, POST, OPTIONS'));
            respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Unsupported request method.']]);
        }
    } else {
        respond(404, ['error' => ['code' => 'not_found', 'message' => 'Endpoint not found.']]);
    }
} catch (HttpError $error) {
    if ($error->errorCode === 'variation_in_progress') header('Retry-After: 3');
    if ($error->status === 401) header('WWW-Authenticate: Bearer');
    respond($error->status, ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']]);
}
