"""Deploy only backend runtime files to the verified cPanel recipe directory."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

import requests

HOST = 'https://ecngx362.inmotionhosting.com:2083'
DIRECTORY = '/home/' + os.environ.get('CPANEL_USER', 'hosting_account') + '/coffeybean.com/CoffeyTableRecipes'
PUBLIC = 'https://coffeybean.com/CoffeyTableRecipes/'


def runtime_files():
    tracked = subprocess.check_output(['git', 'ls-files', '-z']).decode().split('\0')
    allowed = {'LICENSE', 'NOTICE.md', '.htaccess', 'index.php', 'share.php', 'composer.json', 'composer.lock',
               'config/example.php', 'config/.htaccess', 'storage/.htaccess',
               'media/recipe-photos/.htaccess', 'media/recipes/.htaccess'}
    files = [Path(name) for name in tracked if name in allowed or
             name.startswith(('src/', 'assets/email/', 'database/migrations/', 'bin/'))]
    files.extend(path for path in Path('vendor').rglob('*') if path.is_file())
    if not Path('vendor/autoload.php').is_file():
        raise RuntimeError('Install locked Composer dependencies before deployment')
    if any(path.is_symlink() for path in files):
        raise RuntimeError('Runtime package contains a symbolic link')
    return sorted(files)


def require_success(response, operation):
    require_http(response, operation)
    result = response.json()
    if result.get('status', result.get('result', {}).get('status')) != 1:
        raise RuntimeError(operation + ' failed; response details omitted')


def require_http(response, operation):
    try:
        response.raise_for_status()
    except requests.exceptions.HTTPError:
        raise RuntimeError(f'{operation} failed with HTTP {response.status_code}; response details omitted') from None


def require_shared_rollout_ready(session):
    migrations = [Path('database/migrations') / name for name in
                  ('011_shared_recipes.sql', '012_canonical_relationships.sql')]
    if not migrations[-1].is_file():
        return  # Older package; no new schema dependency.
    response = session.get(HOST + '/execute/Fileman/get_file_content',
        params={'dir': DIRECTORY + '/storage/shared-rollout', 'file': 'state.json'}, timeout=30)
    require_success(response, 'Shared rollout readiness')
    try:
        state = json.loads(response.json()['data']['content'])
        expected = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in migrations}
        if (state.get('phase') not in ('migrated', 'verified', 'resumed') or
                state.get('applied_checksums') != expected or
                state.get('backup', {}).get('restore_verified') is not True):
            raise ValueError('unprepared')
    except (KeyError, TypeError, ValueError):
        raise RuntimeError('Verified shared-recipe migration/restore drill required before publishing') from None
    if state['phase'] != 'resumed':
        response = session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': DIRECTORY + '/storage', 'file': 'shared-rollout-maintenance'}, timeout=30)
        require_success(response, 'Maintenance marker verification')


def deploy():
    """Retire only application entry points; keep database, mail and static app."""
    if not all(os.environ.get(name) for name in ('CPANEL_USER', 'CPANEL_API_TOKEN')):
        raise RuntimeError('Configure CPANEL_USER and CPANEL_API_TOKEN repository secrets')
    session = requests.Session()
    session.headers['Authorization'] = f"cpanel {os.environ['CPANEL_USER']}:{os.environ['CPANEL_API_TOKEN']}"
    for name in ('.htaccess', 'index.php', 'share.php', 'bin/purge-deleted-recipes.php'):
        path = Path(name)
        if path.is_symlink():
            raise RuntimeError('Retirement package contains a symbolic link')
        content = path.read_bytes()
        require_success(session.post(HOST + '/execute/Fileman/upload_files',
            data={'dir': DIRECTORY + ('/' + path.parent.as_posix() if path.parent != Path('.') else ''), 'overwrite': '1'},
            files={'file-1': (path.name, content, 'application/octet-stream')}, timeout=60), 'Retirement upload')
        response = session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': DIRECTORY + ('/' + path.parent.as_posix() if path.parent != Path('.') else ''), 'file': path.name}, timeout=30)
        require_success(response, 'Retirement verification')
        hosted = response.json().get('data', {}).get('content')
        if not isinstance(hosted, str) or hashlib.sha256(hosted.encode()).digest() != hashlib.sha256(content).digest():
            raise RuntimeError('Retirement source mismatch: ' + name)
    # Public checks use a new session so cPanel authorization is never forwarded.
    public = requests.Session()
    for route in ('api/v1/health', 'api/v1/admin/users', 'share.php?recipe=1'):
        response = public.get(PUBLIC + route, timeout=60, allow_redirects=False)
        if response.status_code != 410 or response.json().get('error', {}).get('code') != 'service_moved':
            raise RuntimeError('Retired application route is still active')
    if public.get(PUBLIC + 'app/', timeout=60).status_code != 200:
        raise RuntimeError('Existing static app is unavailable')
    for route in ('config/local.php', 'src/AdministrationService.php', 'bin/purge-deleted-recipes.php'):
        if public.get(PUBLIC + route, timeout=60).status_code != 403:
            raise RuntimeError('Private-path protection check failed')
    print('Shared-host application entry points retired; static app retained.')


if __name__ == '__main__':
    try:
        deploy()
    except Exception as error:
        # TLS errors contain the fixed server URL and certificate diagnostic,
        # not request headers. Other HTTP errors stay redacted.
        detail = str(error) if isinstance(error, (RuntimeError, requests.exceptions.SSLError)) else type(error).__name__
        print('Deployment failed: ' + detail + '. Credentials are not logged.', file=sys.stderr)
        sys.exit(1)
