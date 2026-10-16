"""Run the authorized Breakfast Ring grouping once through a temporary CLI-only cron job."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import time
import uuid

spec = importlib.util.spec_from_file_location('migration_jobs', Path(__file__).with_name('migrate-recipe-deletion.py'))
jobs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(jobs)
deploy = jobs.deploy


def generate():
    account = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not token or not re.fullmatch('[a-z0-9_]+', account):
        raise RuntimeError('Hosting credentials required')
    session = deploy.requests.Session()
    session.headers['Authorization'] = f'cpanel {account}:{token}'
    run = uuid.uuid4().hex
    command = '/opt/cpanel/ea-php82/root/usr/bin/php ' + shlex.quote(deploy.DIRECTORY + '/bin/group-breakfast-ring.php') + ' ' + run + ' ' + shlex.quote(account) + ' >/dev/null 2>&1'
    try:
        jobs.cron(session, 'add_line', command=command, minute='*', hour='*', day='*', month='*', weekday='*')
        for _ in range(36):
            response = session.get(deploy.HOST + '/execute/Fileman/get_file_content', params={
                'dir': deploy.DIRECTORY + '/storage', 'file': 'breakfast-groups-' + run + '.json'}, timeout=30)
            deploy.require_http(response, 'Grouping receipt')
            result = response.json()
            if result.get('status') == 1:
                receipt = json.loads(result['data']['content'])
                if receipt.get('status') != 'ok':
                    raise RuntimeError('Hosted Breakfast Ring grouping failed: ' + str(receipt.get('error', 'inspect protected receipt')))
                if receipt.get('title', '').strip().lower() != 'breakfast ring' or set(receipt.get('groups', {})) != {'Dough', 'Sauce'}:
                    raise RuntimeError('Unexpected grouping result')
                Path('storage/breakfast-groups-report.json').write_text(json.dumps(receipt, indent=2))
                print('Verified Breakfast Ring grouping: ' + json.dumps({'recipe_id': receipt['recipe_id'], 'groups': {k: len(v) for k, v in receipt['groups'].items()}, 'ingredient_count': receipt['ingredient_count']}))
                return
            time.sleep(10)
        raise RuntimeError('Grouping receipt unavailable')
    finally:
        jobs.remove_job(session, command)
        print('Temporary Breakfast Ring grouping job removed')


if __name__ == '__main__':
    try:
        generate()
    except Exception as error:
        raise SystemExit('Breakfast Ring grouping failed: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__) + '. Credentials are not logged.')
