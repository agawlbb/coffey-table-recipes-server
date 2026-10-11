<?php
declare(strict_types=1);
require_once __DIR__ . '/HttpError.php';
require_once __DIR__ . '/FirebaseIdentity.php';
require_once __DIR__ . '/HouseholdService.php';
require_once __DIR__ . '/RecipeService.php';
require_once __DIR__ . '/InvitationMail.php';
require_once __DIR__ . '/VerificationMail.php';
require_once __DIR__ . '/VerificationService.php';
require_once __DIR__ . '/FirebaseAccountDirectory.php';
require_once __DIR__ . '/InvitationService.php';
require_once __DIR__ . '/AdministrationService.php';
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

function configuration(): array
{
    $defaults = require dirname(__DIR__) . '/config/example.php';
    $local = dirname(__DIR__) . '/config/local.php';
    $config = is_file($local) ? array_replace_recursive($defaults, require $local) : $defaults;
    $smtpFile = dirname(__DIR__) . '/config/smtp.local.json';
    if (is_file($smtpFile)) $config['smtp'] = array_replace($config['smtp'], json_decode(file_get_contents($smtpFile), true, 16, JSON_THROW_ON_ERROR));
    return $config;
}

function invitationService(PDO $pdo, HouseholdService $households, array $config): InvitationService
{
    return new InvitationService($pdo, $households, [new FirebaseAccountDirectory($config), 'lookup'], [new InvitationMail($config), 'send'], $config['app_url']);
}

function database(array $config): PDO
{
    $db = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function requestBody(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new HttpError(415, 'unsupported_media_type', 'Use application/json.');
    }
    $body = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($body) > 16384) throw new HttpError(413, 'request_too_large', 'Request is too large.');
    try { $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new HttpError(400, 'invalid_json', 'Invalid JSON request.'); }
    if (!is_array($data) || !str_starts_with(ltrim($body), '{')) {
        throw new HttpError(400, 'invalid_json', 'Expected a JSON object.');
    }
    return $data;
}

function requiredName(array $data, string $key): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 200) {
        throw new HttpError(422, 'invalid_name', 'Please enter a name of 1 to 200 characters.');
    }
    return trim($value);
}

function authenticatedIdentity(array $config): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_\-.]+)$/i', $header, $match)) {
        throw new HttpError(401, 'authentication_required', 'Please sign in.');
    }
    if (!class_exists('Firebase\\JWT\\JWT')) {
        throw new HttpError(503, 'auth_unavailable', 'Authentication dependencies are unavailable.');
    }
    return (new FirebaseIdentity($config['firebase_project_id'], 'firebasePublicKeys'))->verify($match[1]);
}
