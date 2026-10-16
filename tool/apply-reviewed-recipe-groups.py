"""Run the authorized reviewed recipe grouping once through a temporary CLI-only cron job."""
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
    command = '/opt/cpanel/ea-php82/root/usr/bin/php ' + shlex.quote(deploy.DIRECTORY + '/bin/apply-reviewed-recipe-groups.php') + ' ' + run + ' ' + shlex.quote(account) + ' >/dev/null 2>&1'
    try:
        jobs.cron(session, 'add_line', command=command, minute='*', hour='*', day='*', month='*', weekday='*')
        for _ in range(36):
            response = session.get(deploy.HOST + '/execute/Fileman/get_file_content', params={
                'dir': deploy.DIRECTORY + '/storage', 'file': 'reviewed-groups-' + run + '.json'}, timeout=30)
            deploy.require_http(response, 'Grouping receipt')
            result = response.json()
            if result.get('status') == 1:
                receipt = json.loads(result['data']['content'])
                if receipt.get('status') != 'ok':
                    raise RuntimeError('Hosted reviewed recipe grouping failed: ' + str(receipt.get('error', 'inspect protected receipt')))
                if receipt.get('recipe_count') != 9 or len(receipt.get('recipes', [])) != 9:
                    raise RuntimeError('Unexpected reviewed grouping result')
                Path('storage/reviewed-groups-report.json').write_text(json.dumps(receipt, indent=2))
                print('Verified reviewed recipe grouping: ' + json.dumps(receipt))
                return
            time.sleep(10)
        raise RuntimeError('Grouping receipt unavailable')
    finally:
        jobs.remove_job(session, command)
        print('Temporary reviewed recipe grouping job removed')


if __name__ == '__main__':
    try:
        generate()
    except Exception as error:
        raise SystemExit('reviewed recipe grouping failed: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__) + '. Credentials are not logged.')
