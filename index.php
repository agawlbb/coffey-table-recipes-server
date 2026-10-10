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
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/recipes(?:/([1-9][0-9]{0,18}))?$#', $route, $recipeMatch)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo);
        $user = $households->bootstrap($identity);
        $households->membership($user['id'], $recipeMatch[1]);
        if ($method !== 'GET') {
            header('Allow: GET, OPTIONS');
            throw new HttpError(405, 'method_not_allowed', 'Use GET.');
        }
        $recipes = new RecipeService($pdo, $households);
        if (isset($recipeMatch[2])) {
            respond(200, ['recipe' => $recipes->detail($user['id'], $recipeMatch[1], $recipeMatch[2])]);
        } else {
            $query = $_GET['q'] ?? '';
            $offset = $_GET['offset'] ?? '0';
            $category = $_GET['category'] ?? '';
            $scope = $_GET['scope'] ?? '';
            $tree = $_GET['tree'] ?? '0';
            if (!is_string($query) || !is_string($offset) || !preg_match('/^[0-9]{1,6}$/', $offset)) throw new HttpError(400, 'invalid_filter', 'Invalid recipe search.');
            if (!is_string($category)) throw new HttpError(400, 'invalid_filter', 'Invalid recipe category.');
            if (!is_string($scope) || !is_string($tree) || !in_array($tree, ['0', '1'], true)) throw new HttpError(400, 'invalid_filter', 'Invalid category scope.');
            respond(200, $recipes->browse($user['id'], $recipeMatch[1], trim($query), (int) $offset, trim($category), $scope, $tree === '1'));
        }
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/people/([1-9][0-9]{0,18})/invitations$#', $route, $inviteMatch)
        || preg_match('#^/invitations/([1-9][0-9]{0,18})/respond$#', $route, $respondMatch)) {
        $identity = authenticatedIdentity($config);
        $pdo = database($config);
        $households = new HouseholdService($pdo);
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
    } elseif (preg_match('#^/households/([1-9][0-9]{0,18})/name$#', $route, $nameMatch)) {
        $identity = authenticatedIdentity($config);
        $service = new HouseholdService(database($config));
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
        $service = new HouseholdService($pdo);
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
