import hashlib
import json
from pathlib import Path
import secrets
import os
import sys
import requests

HOST = 'https://ecngx362.inmotionhosting.com:2083'
DIRECTORY = '/home/' + os.environ.get('CPANEL_USER', 'hosting_account') + '/coffeybean.com/CoffeyTableRecipes'
PUBLIC = 'https://coffeybean.com/CoffeyTableRecipes/'
credentials = {'user': os.environ['CPANEL_USER'], 'token': os.environ['CPANEL_API_TOKEN']}
if not credentials['user'] or not credentials['user'].replace('_', '').isalnum():
    raise RuntimeError('Verified hosting account required')
session = requests.Session()
session.headers['Authorization'] = f"cpanel {credentials['user']}:{credentials['token']}"

def checked(response):
    if response.status_code != 200:
        raise RuntimeError('Hosting request HTTP ' + str(response.status_code))
    data = response.json()
    if data.get('status', data.get('result', {}).get('status')) != 1:
        raise RuntimeError('Hosting operation failed; details omitted')
    return data

def run_cleanup():
    state = {'filename': 'action-cleanup-' + secrets.token_hex(16) + '.php', 'token': secrets.token_hex(32)}
    token_hash = hashlib.sha256(state['token'].encode()).hexdigest()
    php = '''<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_CLEANUP_TOKEN'] ?? ''))) { http_response_code(404); exit; }
require_once __DIR__.'/src/bootstrap.php';
require_once __DIR__.'/src/RecipeActionCleanup.php';
try {
  $mode=$_POST['mode'] ?? '';
  if (!in_array($mode,['dry-run','apply'],true)) throw new RuntimeException('Invalid mode');
  $lock=fopen(__DIR__.'/storage/action-cleanup-once.lock','c');
  if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Lock failed');
  $marker=__DIR__.'/storage/action-cleanup-completed.json';
  if ($mode==='apply' && is_file($marker)) { echo file_get_contents($marker); exit; }
  $report=RecipeActionCleanup::run(database(configuration()),__DIR__.'/storage',$mode==='apply');
  $json=json_encode($report,JSON_THROW_ON_ERROR);
  if ($mode==='apply') { file_put_contents($marker,$json,LOCK_EX); chmod($marker,0600); }
  echo $json;
} catch (Throwable $error) { http_response_code(500); echo json_encode(['error'=>'Cleanup failed; transaction rolled back.']); }
'''.replace('TOKEN_HASH', token_hash)
    checked(session.post(HOST + '/execute/Fileman/upload_files', data={'dir': DIRECTORY, 'overwrite': '0'},
        files={'file-1': (state['filename'], php.encode(), 'application/x-httpd-php')}, timeout=60))
    public = requests.Session()
    public.headers['User-Agent'] = 'CoffeyTableRecipes maintenance verification'
    try:
        for index, mode in enumerate(['dry-run', 'apply', 'dry-run']):
            response = public.post(PUBLIC + state['filename'], headers={'X-Cleanup-Token':state['token']}, data={'mode':mode}, timeout=90)
            if response.status_code != 200:
                raise RuntimeError('Cleanup HTTP ' + str(response.status_code))
            report = response.json()
            if 'error' in report:
                raise RuntimeError('Cleanup failed')
            if index == 2 and (report['remove'] or report['convert_timed_rest_to_timer']):
                raise RuntimeError('Unwanted hosted actions remain after cleanup')
            print(mode + ': ' + json.dumps(report))
            if mode == 'apply':
                Path('storage/action-cleanup-report.json').write_text(json.dumps(report,indent=2))
    finally:
        response = session.post(HOST + '/json-api/cpanel', data={'cpanel_jsonapi_user': credentials['user'],
            'cpanel_jsonapi_apiversion':'2','cpanel_jsonapi_module':'Fileman','cpanel_jsonapi_func':'fileop',
            'op':'unlink','sourcefiles':DIRECTORY + '/' + state['filename'],'doubledecode':'1'}, timeout=60)
        data = response.json().get('cpanelresult', {}) if response.status_code == 200 else {}
        if data.get('event', {}).get('result') != 1 or any(item.get('result') != 1 for item in data.get('data', [])):
            raise RuntimeError('Temporary handler removal failed')
        print('Temporary cleanup handler removed')

if __name__ == '__main__':
    run_cleanup()
