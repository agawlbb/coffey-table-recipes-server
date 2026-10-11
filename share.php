<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/RecipeShare.php';
require __DIR__ . '/src/RecipeSharePage.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: http:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
header('X-Robots-Tag: noindex, nofollow');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        throw new HttpError(405, 'method_not_allowed', 'Use GET.');
    }
    $config = configuration();
    $token = $_GET['token'] ?? '';
    if (!is_string($token)) throw new HttpError(404, 'share_not_found', 'This recipe link is unavailable.');
    [$user, $household, $recipe] = (new RecipeShare($config['recipe_share_key']))->resolve($token);
    $pdo = database($config);
    $data = (new RecipeService($pdo, new HouseholdService($pdo)))->detail($user, $household, $recipe);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo recipeSharePage($data);
} catch (Throwable $error) {
    $status = $error instanceof HttpError ? $error->status : 500;
    if ($status === 403) $status = 404;
    http_response_code($status);
    if (!$error instanceof HttpError) error_log((string) $error);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo recipeSharePage(['title' => 'Recipe unavailable', 'description' => 'This recipe link is unavailable. Please ask the sender for a new link.']);
}
