"""Maintain only the exact daily recipe-expiry job; preserve other account jobs."""
import importlib.util
import os
from pathlib import Path
import re
import shlex

spec = importlib.util.spec_from_file_location('recipe_migration', Path(__file__).with_name('migrate-recipe-deletion.py'))
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)


def schedule():
    account = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not token or not re.fullmatch('[a-z0-9_]+', account):
        raise RuntimeError('Configure hosting credentials')
    session = migration.deploy.requests.Session()
    session.headers['Authorization'] = f'cpanel {account}:{token}'
    command = '/opt/cpanel/ea-php82/root/usr/bin/php ' + shlex.quote(
        migration.deploy.DIRECTORY + '/bin/purge-deleted-recipes.php')
    expected = {'minute': '15', 'hour': '3', 'day': '*', 'month': '*', 'weekday': '*'}
    jobs = [row for row in migration.cron(session, 'listcron') if row.get('command') == command]
    if len(jobs) != 1 or any(str(jobs[0].get(key)) != value for key, value in expected.items()):
        if jobs:
            migration.remove_job(session, command)
        migration.cron(session, 'add_line', command=command, **expected)
    jobs = [row for row in migration.cron(session, 'listcron') if row.get('command') == command]
    if len(jobs) != 1 or any(str(jobs[0].get(key)) != value for key, value in expected.items()):
        raise RuntimeError('Daily recipe cleanup schedule verification failed')
    print('Verified daily recipe cleanup at 03:15 server time.')


if __name__ == '__main__':
    try:
        schedule()
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        raise SystemExit('Recipe cleanup scheduling failed: ' + detail + '. Credentials are not logged.')
