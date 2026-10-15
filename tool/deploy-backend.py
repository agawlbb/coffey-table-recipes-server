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


def deploy():
    if not all(os.environ.get(name) for name in ('CPANEL_USER', 'CPANEL_API_TOKEN')):
        raise RuntimeError('Configure CPANEL_USER and CPANEL_API_TOKEN repository secrets')
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD']).decode().strip()
    if not re.fullmatch('[0-9a-f]{40}', commit):
        raise RuntimeError('Invalid source commit')
    files = runtime_files()
    output = Path('storage') / ('backend-' + commit + '.zip')
    manifest = {path.as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in files}
    metadata = {'commit': commit, 'package_sha256': hashlib.sha256(
        json.dumps(manifest, sort_keys=True).encode()).hexdigest()}
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in files:
            archive.write(path, path.as_posix())
    session = requests.Session()
    session.headers['Authorization'] = f"cpanel {os.environ['CPANEL_USER']}:{os.environ['CPANEL_API_TOKEN']}"
    # Upload into protected storage, never into a publicly downloadable archive.
    with output.open('rb') as stream:
        require_success(session.post(HOST + '/execute/Fileman/upload_files',
            data={'dir': DIRECTORY + '/storage', 'overwrite': '1'},
            files={'file-1': (output.name, stream, 'application/zip')}, timeout=180), 'Upload')
    response = session.post(HOST + '/json-api/cpanel', data={
        'cpanel_jsonapi_user': os.environ['CPANEL_USER'], 'cpanel_jsonapi_apiversion': '2',
        'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop',
        'op': 'extract', 'sourcefiles': DIRECTORY + '/storage/' + output.name,
        'destfiles': DIRECTORY, 'doubledecode': '1'}, timeout=180)
    require_http(response, 'Extraction')
    result = response.json()['cpanelresult']
    items = result.get('data', [])
    if result.get('event', {}).get('result') != 1 or not items or any(
            item.get('result') != 1 or item.get('dest', '').rstrip('/') != DIRECTORY for item in items):
        raise RuntimeError('Extraction did not confirm the intended application directory')
    # A receipt alone cannot prove archive extraction replaced existing PHP files.
    # Read back the variation runtime source privately; never log its contents.
    for name in ('src/RecipeImportService.php', 'src/RecipeVariationService.php'):
        if name not in manifest:
            continue
        response = session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': DIRECTORY + '/src', 'file': Path(name).name}, timeout=30)
        require_success(response, 'Runtime source verification')
        content = response.json().get('data', {}).get('content')
        if not isinstance(content, str) or hashlib.sha256(content.encode()).hexdigest() != manifest[name]:
            raise RuntimeError('Hosted runtime source does not match package: ' + name)
    # Public checks deliberately use a different session without cPanel credentials.
    public = requests.Session()
    public.headers['User-Agent'] = 'CoffeyTableRecipes deployment verification'
    response = public.get(PUBLIC + 'api/v1/health', timeout=60)
    response.raise_for_status()
    if response.json().get('status') != 'ok':
        raise RuntimeError('Hosted liveness check failed')
    response = public.options(PUBLIC + 'api/v1/me', headers={
        'Origin': 'http://localhost', 'Access-Control-Request-Method': 'GET',
        'Access-Control-Request-Headers': 'authorization,content-type'}, timeout=60)
    if response.status_code != 204 or response.headers.get('Access-Control-Allow-Origin') != 'http://localhost':
        raise RuntimeError('Hosted localhost CORS check failed')
    for name in ('config/local.php', 'vendor/autoload.php', 'storage/' + output.name):
        if public.get(PUBLIC + name, timeout=60).status_code != 403:
            raise RuntimeError('Hosted internal-path protection check failed')
    media = public.get(PUBLIC + 'media/recipes/', headers={'Origin': 'http://localhost'}, timeout=60)
    if media.headers.get('Access-Control-Allow-Origin') != '*':
        raise RuntimeError('Hosted public image CORS check failed')
    # Publish the receipt only after runtime checks pass. Contains no credentials.
    require_success(session.post(HOST + '/execute/Fileman/upload_files',
        data={'dir': DIRECTORY, 'overwrite': '1'}, files={
        'file-1': ('backend-version.json', json.dumps(metadata).encode(), 'application/json')},
        timeout=60), 'Deployment receipt upload')
    response = public.get(PUBLIC + 'backend-version.json', params={'commit': commit}, timeout=60)
    response.raise_for_status()
    if response.json() != metadata:
        raise RuntimeError('Hosted deployment receipt mismatch')
    # Keep the latest protected package for recovery; remove older packages manually.
    print('Verified hosted backend commit ' + commit)


if __name__ == '__main__':
    try:
        deploy()
    except Exception as error:
        # TLS errors contain the fixed server URL and certificate diagnostic,
        # not request headers. Other HTTP errors stay redacted.
        detail = str(error) if isinstance(error, (RuntimeError, requests.exceptions.SSLError)) else type(error).__name__
        print('Deployment failed: ' + detail + '. Credentials are not logged.', file=sys.stderr)
        sys.exit(1)
