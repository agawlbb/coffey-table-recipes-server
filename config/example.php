<?php
declare(strict_types=1);

// Copy to local.php. Never commit local.php or server credentials.
return [
    'environment' => 'development',
    // Private random key, at least 32 characters. Rotating invalidates all shared links.
    'recipe_share_key' => '',
    'base_path' => '/CoffeyTableRecipes',
    'allowed_origins' => ['http://localhost:8080'],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'coffey_table_recipes',
        'user' => '',
        'password' => '',
    ],
    'firebase_project_id' => '',
    'firebase_service_account_file' => dirname(__DIR__) . '/config/firebase-admin.local.json',
    'app_url' => 'https://coffeybean.com/CoffeyTableRecipes/app/',
    'smtp' => [
        'host' => '', 'port' => 465, 'encryption' => 'ssl',
        'username' => '', 'password' => '',
        'from_email' => 'CoffeyTableRecipies@coffeybean.com',
        'from_name' => 'Coffey Table Recipes',
    ],
];
