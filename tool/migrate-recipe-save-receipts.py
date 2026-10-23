"""Apply only reviewed migration 017 via a temporary, CLI-only hosting job."""
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import time
import uuid

spec = importlib.util.spec_from_file_location('backend_deploy', Path(__file__).with_name('deploy-backend.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


def cron(session, function, **parameters):
    response = session.post(deploy.HOST + '/json-api/cpanel', data={
        'cpanel_jsonapi_user': os.environ['CPANEL_USER'], 'cpanel_jsonapi_apiversion': '2',
        'cpanel_jsonapi_module': 'Cron', 'cpanel_jsonapi_func': function, **parameters}, timeout=60)
    deploy.require_http(response, 'Migration scheduling')
    result = response.json()['cpanelresult']
    if result.get('event', {}).get('result') != 1 or any(
            row.get('status', row.get('result', 1)) != 1 for row in result.get('data', [])):
        raise RuntimeError('Migration scheduling failed; response details omitted')
    return result.get('data', [])


def remove_job(session, command):
    # Match the exact dedicated command; retain all other account cron entries.
    for row in sorted(cron(session, 'listcron'), key=lambda row: int(row.get('count', 0)), reverse=True):
        if row.get('command') == command:
            cron(session, 'remove_line', line=row['count'])
    if any(row.get('command') == command for row in cron(session, 'listcron')):
        raise RuntimeError('Temporary migration job cleanup failed')


def run_once(session, account):
    run = uuid.uuid4().hex
    filename = f'recipe-save-receipts-{run}.json'
    command = '/opt/cpanel/ea-php82/root/usr/bin/php ' + shlex.quote(
        deploy.DIRECTORY + '/bin/enable-recipe-save-receipts.php') + ' ' + run + ' ' + shlex.quote(account) + ' >/dev/null 2>&1'
    try:
        cron(session, 'add_line', command=command, minute='*', hour='*', day='*', month='*', weekday='*')
        for _ in range(18):
            response = session.get(deploy.HOST + '/execute/Fileman/get_file_content',
                params={'dir': deploy.DIRECTORY + '/storage', 'file': filename}, timeout=60)
            deploy.require_http(response, 'Migration verification')
            result = response.json()
            if result.get('status') == 1:
                receipt = json.loads(result['data']['content'])
                return receipt
            time.sleep(10)
        raise RuntimeError('Hosted migration receipt unavailable')
    finally:
        remove_job(session, command)


def mysql(session, function, **parameters):
    response = session.post(deploy.HOST + '/execute/Mysql/' + function, data=parameters, timeout=60)
    deploy.require_success(response, 'Temporary migration permissions')
    return response.json().get('data')


def temporary_schema_migration(session, account, user):
    if not isinstance(user, str) or not re.fullmatch(re.escape(account) + r'_[A-Za-z0-9_]+', user):
        raise RuntimeError('Unexpected recipe database user; no privileges changed')
    target = {'user': user, 'database': account + '_recipes'}
    original = mysql(session, 'get_privileges_on_database', **target)
    if not isinstance(original, list) or not original or any(
            not isinstance(value, str) or not re.fullmatch('[A-Z ]+', value) for value in original):
        raise RuntimeError('Unrecognized database privileges; no privileges changed')
    # Keep recovery metadata only in the protected hosting directory, never in logs.
    metadata = json.dumps({**target, 'privileges': original}).encode()
    deploy.require_success(session.post(deploy.HOST + '/execute/Fileman/upload_files',
        data={'dir': deploy.DIRECTORY + '/storage', 'overwrite': '0'},
        files={'file-1': ('recipe-save-receipts-privileges-' + uuid.uuid4().hex + '.json', metadata, 'application/json')},
        timeout=60), 'Original privilege backup')
    try:
        mysql(session, 'set_privileges_on_database', **target,
              privileges=','.join(sorted(set(original) | {'ALTER', 'CREATE', 'REFERENCES'})))
        return run_once(session, account)
    finally:
        mysql(session, 'set_privileges_on_database', **target, privileges=','.join(original))
        restored = mysql(session, 'get_privileges_on_database', **target)
        if not isinstance(restored, list) or set(restored) != set(original):
            raise RuntimeError('Original database permission verification failed')
        print('Verified restoration of original recipe database permissions.')


def migrate():
    account = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not token or not re.fullmatch('[a-z0-9_]+', account):
        raise RuntimeError('Configure hosting credentials')
    session = deploy.requests.Session()
    session.headers['Authorization'] = f'cpanel {account}:{token}'
    # Stage only the CLI job and additive SQL for the receipt table.
    # The runtime is published only after schema verification succeeds.
    for name in ('bin/enable-recipe-save-receipts.php', 'database/migrations/017_recipe_save_receipts.sql'):
        path = Path(name)
        with path.open('rb') as stream:
            deploy.require_success(session.post(deploy.HOST + '/execute/Fileman/upload_files',
                data={'dir': deploy.DIRECTORY + '/' + str(path.parent), 'overwrite': '1'},
                files={'file-1': (path.name, stream, 'application/octet-stream')}, timeout=60), 'Migration upload')
    receipt = run_once(session, account)
    if receipt.get('status') == 'error' and receipt.get('stage') == 'migration' and receipt.get('database_error') == 1142:
        receipt = temporary_schema_migration(session, account, receipt.get('database_user'))
    if receipt.get('status') != 'ok':
        stage = receipt.get('stage')
        if stage not in ('target', 'database', 'backup', 'migration', 'record', 'verify'):
            stage = 'unknown'
        code = receipt.get('database_error')
        detail = f' (database error {code})' if type(code) is int else ''
        raise RuntimeError('Hosted migration failed at ' + stage + detail + '; inspect protected receipt')
    if receipt.get('migration') != '017_recipe_save_receipts.sql' or type(receipt.get('recipes')) is not int or receipt['recipes'] < 0 or receipt.get('service_reads_verified') is not True:
        raise RuntimeError('Unexpected recipe migration receipt')
    safe = {key: receipt.get(key) for key in ('status', 'migration', 'backup', 'recipes', 'service_reads_verified')}
    print('Verified recipe save receipts migration: ' + json.dumps(safe))


if __name__ == '__main__':
    try:
        migrate()
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        raise SystemExit('Migration failed: ' + detail + '. Credentials are not logged.')

