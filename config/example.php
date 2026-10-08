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
];
