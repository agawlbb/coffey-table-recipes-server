<?php
declare(strict_types=1);
// No database or private configuration is loaded on the retired application host.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['error'=>['code'=>'service_moved','message'=>'Use the VPS recipe service.'],
    'service'=>'https://ctr.coffeybean.com']);
