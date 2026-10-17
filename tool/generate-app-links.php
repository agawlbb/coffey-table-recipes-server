<?php
declare(strict_types=1);
// Local file generator only. No network calls, signing keys or deployment.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 4 || !preg_match('/^[A-Z0-9]{10}$/D',$argv[1]) || !preg_match('/^(?:[A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}$/D',$argv[2])) {
    fwrite(STDERR,"Usage: php tool/generate-app-links.php APPLE_TEAM_ID ANDROID_SIGNING_SHA256 NEW_OUTPUT_DIRECTORY\n"); exit(1);
}
$directory=$argv[3];
if (file_exists($directory)) { fwrite(STDERR,"Choose a new output directory to avoid overwriting existing association files.\n"); exit(1); }
if (!mkdir($directory,0755,true)) throw new RuntimeException('Cannot create output directory.');
$files=[
    'apple-app-site-association'=>['applinks'=>['details'=>[['appIDs'=>[$argv[1].'.com.coffeytable.coffeyTableRecipes'],'components'=>[['/'=>'/CoffeyTableRecipes/share.php']]]]]],
    'assetlinks.json'=>[['relation'=>['delegate_permission/common.handle_all_urls'],'target'=>['namespace'=>'android_app','package_name'=>'com.coffeytable.coffey_table_recipes','sha256_cert_fingerprints'=>[strtoupper($argv[2])]]]],
];
foreach ($files as $name=>$value) file_put_contents($directory.DIRECTORY_SEPARATOR.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "Generated local association files. Review and publish at the domain-root /.well-known/ only during an authorized deployment.\n";
