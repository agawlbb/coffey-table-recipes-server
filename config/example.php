<?php
declare(strict_types=1);

// Copy to local.php. Never commit local.php or server credentials.
return [
    'environment' => 'development',
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
    'recipe_ai' => [
        'api_key' => '', // Set only in ignored config/local.php or OPENAI_API_KEY.
        'model' => 'gpt-5.4-mini',
        'image_model' => 'gpt-image-2.5-flare', // Low-quality 816x816 draft photos.
        'pdftotext' => '', // Optional absolute Poppler pdftotext executable path.
        'tesseract' => '', // Optional absolute Tesseract executable path.
    ],
    'smtp' => [
        'host' => '', 'port' => 465, 'encryption' => 'ssl',
        'username' => '', 'password' => '',
        'from_email' => 'CoffeyTableRecipies@coffeybean.com',
        'from_name' => 'Coffey Table Recipes',
    ],
];
