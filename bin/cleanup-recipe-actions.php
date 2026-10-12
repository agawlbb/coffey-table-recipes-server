<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/RecipeActionCleanup.php';
$options=getopt('', ['dry-run','apply']);
if (isset($options['dry-run'])===isset($options['apply'])) throw new RuntimeException('Choose --dry-run or --apply.');
echo json_encode(RecipeActionCleanup::run(database(configuration()),dirname(__DIR__).'/storage',isset($options['apply'])),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
