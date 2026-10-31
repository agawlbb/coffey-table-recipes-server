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
    if (preg_match('#^/(?:me(?:/verification-email|/child-mode)?|admin/users/[1-9][0-9]*/reset-child-mode|households|households/[1-9][0-9]*/people/[1-9][0-9]*/invitations|invitations/[1-9][0-9]*/respond)$#D',$route)) {
        throw new HttpError(410,'account_moved','Account setup and verification are available through the VPS WebSocket.');
    }
    if (preg_match('#^/households/[1-9][0-9]*/(?:recipe-import|recipe-photo|recipe-category|category-image|recipes/[1-9][0-9]*/variation)$#D',$route) || preg_match('#^/admin/parent-categories(?:/[1-9][0-9]*/category-image)?$#D',$route)) {
        throw new HttpError(410,'ai_moved','AI requests are available through the VPS WebSocket.');
    }
    if ($route === '/home-assistant/shopping-list' || preg_match('#^/(?:me/(?:timers|timer-devices(?:/remove)?)|recipes/[1-9][0-9]*|households/[1-9][0-9]*/(?:shopping-list(?:/sync-request)?|snapshot|name|people(?:/me/name)?|restaurants|recipe-blank|meal-plan(?:/.*)?|recipes(?:/(?![1-9][0-9]*/variation$).*)?))$#D', $route)) {
        throw new HttpError(410, 'data_moved', 'Data synchronization is available through the VPS.');
    }
    if (!ChildMode::allowed($route,$method) && !str_starts_with($route,'/home-assistant/')) {
        $identity=authenticatedIdentity($config);
        (new ChildMode(database($config)))->guard($identity,$route,$method);
    }
    if ($method === 'OPTIONS') {
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
