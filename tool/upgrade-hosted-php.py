"""Upgrade only coffeybean.com after CI and hosted CLI checks, with rollback."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import sys
import time
import uuid

spec = importlib.util.spec_from_file_location('migration', Path(__file__).with_name('migrate-recipe-deletion.py'))
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)
deploy = migration.deploy
DOMAIN = 'coffeybean.com'
TARGET = 'ea-php84'
PHP_BINARY = '/opt/cpanel/ea-php84/root/usr/bin/php'


def api_result(response):
    deploy.require_success(response, 'PHP settings')
    return response.json()


def versions(session):
    result = api_result(session.get(deploy.HOST + '/execute/LangPHP/php_get_vhost_versions',
        timeout=60, allow_redirects=False))
    rows = result.get('data')
    if not isinstance(rows, list) or any(not isinstance(row, dict) or not isinstance(row.get('vhost'), str)
            or not isinstance(row.get('version'), str) for row in rows):
        raise RuntimeError('Hosted domain PHP settings could not be verified')
    mapping = {row['vhost']: row for row in rows}
    if len(mapping) != len(rows) or DOMAIN not in mapping:
        raise RuntimeError('Hosted domain PHP settings were ambiguous')
    return mapping


def installed(session):
    data = api_result(session.get(deploy.HOST + '/execute/LangPHP/php_get_installed_versions',
        timeout=60, allow_redirects=False)).get('data')
    values = data.get('versions') if isinstance(data, dict) else data
    if not isinstance(values, list) or any(not isinstance(value, str) or not re.fullmatch(r'(?:ea|alt)-php[0-9]{2,3}', value) for value in values):
        raise RuntimeError('Installed PHP versions could not be resolved')
    return values


def handler(session, version):
    return api_result(session.get(deploy.HOST + '/execute/LangPHP/php_get_handler',
        params={'version':version}, timeout=60, allow_redirects=False)).get('data')


def set_version(session, version):
    if not re.fullmatch(r'ea-php[0-9]{2,3}|inherit', version):
        raise RuntimeError('Invalid PHP handler')
    # LangPHP documents this operation as GET with query parameters.
    response = session.get(deploy.HOST + '/execute/LangPHP/php_set_vhost_versions',
        params={'version': version, 'vhost': DOMAIN}, timeout=60, allow_redirects=False)
    if response.status_code == 200:
        result = response.json()
        if result.get('status') != 1:
            # This endpoint returns only PHP settings errors. Redact hosting
            # credentials and potential secret-shaped values before diagnostics.
            errors = result.get('errors') or []
            if isinstance(errors, list):
                for error in errors:
                    if isinstance(error, str):
                        for secret in (os.environ.get('CPANEL_API_TOKEN', ''), os.environ.get('CPANEL_USER', '')):
                            if secret:
                                error = error.replace(secret, '[redacted]')
                        error = re.sub(r'(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)', '[redacted]', error)
                        print('PHP handler API error: ' + error[:500])
            # Some addon-domain mappings are stale on this account. cPanel can
            # finish changing the requested domain before failing on that alias.
            # Accept only this known error, only if independent readback confirms
            # the requested handler. The caller still checks every other domain
            # and the real web runtime before declaring success.
            if isinstance(errors, list) and errors and all(isinstance(error, str) and
                    'No users correspond to the domain' in error for error in errors):
                current = versions(session)
                if current[DOMAIN]['version'] == version:
                    print('Verified requested domain handler despite stale addon-alias API error.')
                    return
    api_result(response)


def verify_scope(before, after, expected):
    if after[DOMAIN]['version'] != expected:
        raise RuntimeError('Recipe domain PHP setting did not match')
    original = {name: row for name, row in before.items() if name != DOMAIN}
    current = {name: row for name, row in after.items() if name != DOMAIN}
    if original != current:
        differences = [{'domain':name, 'fields':sorted(key for key in set(original.get(name, {})) | set(current.get(name, {}))
            if original.get(name, {}).get(key) != current.get(name, {}).get(key))}
            for name in sorted(set(original) | set(current)) if original.get(name) != current.get(name)]
        print('PHP domain setting differences: ' + json.dumps(differences))
        raise RuntimeError('An unrelated domain PHP setting changed; inspect hosting settings')


def upload(session, directory, name, content, overwrite=False):
    deploy.require_success(session.post(deploy.HOST + '/execute/Fileman/upload_files',
        data={'dir': directory, 'overwrite': '1' if overwrite else '0'},
        files={'file-1': (name, content, 'application/octet-stream')},
        timeout=60, allow_redirects=False), 'PHP upgrade verification upload')


def read_file(session, directory, name):
    response = session.get(deploy.HOST + '/execute/Fileman/get_file_content',
        params={'dir': directory, 'file': name}, timeout=60, allow_redirects=False)
    deploy.require_http(response, 'PHP upgrade verification readback')
    data = response.json()
    if data.get('status') != 1:
        return None
    content = data.get('data', {}).get('content')
    if not isinstance(content, str):
        raise RuntimeError('PHP upgrade verification readback was invalid')
    return content


def php_handler_content(content, version):
    if not re.fullmatch(r'ea-php[0-9]{2,3}', version):
        raise RuntimeError('Invalid directory PHP handler')
    pattern = r'(?m)^(\s*AddHandler\s+)application/x-httpd-ea-php[0-9]{2,3}(\s+[^\r\n]+)$'
    if len(list(re.finditer(pattern, content))) != 1:
        raise RuntimeError('Hosted PHP directory handler was ambiguous; no file overwritten')
    return re.sub(pattern, lambda match: match[1] + 'application/x-httpd-' + version + match[2], content)


def write_handler(session, root, content):
    upload(session, root, '.htaccess', content.encode(), overwrite=True)
    if read_file(session, root, '.htaccess') != content:
        raise RuntimeError('Hosted PHP directory handler readback did not match')


def check_probe(result):
    required = ('extensions', 'composer_bootstrap', 'database_read', 'import_preprocessing')
    if result.get('status') != 'ok' or not re.fullmatch(r'8\.4\.\d+', result.get('php_version', '')) or any(
            result.get(key) is not True for key in required):
        version = result.get('php_version', '')
        sapi = result.get('sapi', '')
        print('Hosted PHP runtime mismatch: ' + json.dumps({'php_version':version if re.fullmatch(r'[0-9.]+', version) else 'unknown',
            'sapi':sapi if re.fullmatch(r'[a-z_-]+', sapi) else 'unknown',
            'passed_checks':[key for key in required if result.get(key) is True]}))
        raise RuntimeError('Hosted PHP 8.4 runtime checks failed; response details omitted')
    return {key: result.get(key) for key in ('php_version', 'sapi', *required, 'recipe_read')}


def cli_probe(session, account, filename, token, receipt):
    command = PHP_BINARY + ' ' + shlex.quote(deploy.DIRECTORY + '/' + filename) + ' ' + shlex.quote(token) + ' >/dev/null 2>&1'
    try:
        migration.cron(session, 'add_line', command=command, minute='*', hour='*', day='*', month='*', weekday='*')
        for _ in range(18):
            content = read_file(session, deploy.DIRECTORY + '/storage', receipt)
            if content is not None:
                return json.loads(content)
            time.sleep(10)
        raise RuntimeError('Hosted PHP 8.4 CLI verification timed out')
    finally:
        migration.remove_job(session, command)


def public_checks(public, probe_url, token, verify_version=True):
    response = public.get(deploy.PUBLIC + 'api/v1/health', timeout=30)
    deploy.require_http(response, 'Hosted health')
    if response.json().get('status') != 'ok':
        raise RuntimeError('Hosted health check failed')
    if public.get(deploy.PUBLIC + 'api/v1/me', timeout=30).status_code != 401:
        raise RuntimeError('Hosted authentication guard check failed')
    response = public.options(deploy.PUBLIC + 'api/v1/me', headers={
        'Origin':'http://localhost', 'Access-Control-Request-Method':'GET',
        'Access-Control-Request-Headers':'authorization,content-type'}, timeout=30)
    if response.status_code != 204 or response.headers.get('Access-Control-Allow-Origin') != 'http://localhost':
        raise RuntimeError('Hosted CORS check failed')
    for path in ('config/local.php', 'config/recipe-ai.local.json', 'vendor/autoload.php', 'storage/'):
        if public.get(deploy.PUBLIC + path, timeout=30).status_code != 403:
            raise RuntimeError('Hosted internal-path protection check failed')
    response = public.get(probe_url, headers={'X-Recipe-PHP-Check':token}, timeout=30)
    deploy.require_http(response, 'Hosted PHP runtime')
    result = response.json()
    if verify_version:
        check_probe(result)
    elif result.get('status') != 'ok':
        raise RuntimeError('Original hosted PHP runtime was unhealthy')
    share = result.get('share_id')
    if share is not None:
        if not re.fullmatch(r'[1-9][0-9]*', share):
            raise RuntimeError('Invalid public-share verification identifier')
        response = public.get(deploy.PUBLIC + 'share.php', params={'id':share}, timeout=30)
        deploy.require_http(response, 'Hosted public recipe share')
        if 'text/html' not in response.headers.get('Content-Type', ''):
            raise RuntimeError('Hosted recipe share response was invalid')
    photo = result.get('photo_filename')
    if photo is not None:
        if not re.fullmatch(r'[a-f0-9]{48}\.(jpg|png|webp)', photo):
            raise RuntimeError('Invalid photo verification filename')
        response = public.get(deploy.PUBLIC + 'media/recipes/' + photo, timeout=30)
        deploy.require_http(response, 'Hosted recipe photo')
        if not response.headers.get('Content-Type', '').startswith('image/'):
            raise RuntimeError('Hosted photo response was invalid')
    return result


def quarantine_probe(session, account, filename):
    destination = '/home/' + account + '/' + filename
    response = session.post(deploy.HOST + '/json-api/cpanel', data={
        'cpanel_jsonapi_user':account, 'cpanel_jsonapi_apiversion':'2',
        'cpanel_jsonapi_module':'Fileman', 'cpanel_jsonapi_func':'fileop',
        'op':'move', 'sourcefiles':deploy.DIRECTORY + '/' + filename,
        'destfiles':destination, 'doubledecode':'1'}, timeout=60, allow_redirects=False)
    deploy.require_http(response, 'Temporary PHP probe cleanup')
    result = response.json().get('cpanelresult', {})
    items = result.get('data', [])
    if result.get('event', {}).get('result') != 1 or not items or any(row.get('result') != 1 for row in items):
        raise RuntimeError('Temporary PHP probe cleanup failed')
    if read_file(session, '/home/' + account, filename) is None:
        raise RuntimeError('Temporary PHP probe cleanup readback failed')


def upgrade(inspect=False):
    account = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not re.fullmatch(r'[a-z0-9_]+', account) or not token:
        raise RuntimeError('Hosting credentials unavailable')
    session = deploy.requests.Session()
    session.headers['Authorization'] = f'cpanel {account}:{token}'
    before = versions(session)
    available = installed(session)
    root = '/home/' + account + '/coffeybean.com'
    if before[DOMAIN].get('documentroot', '').rstrip('/') != root:
        raise RuntimeError('Recipe domain document root did not match; no settings changed')
    print('Hosted PHP inspection: ' + json.dumps({'domain':DOMAIN, 'current':before[DOMAIN]['version'],
        'php_fpm':before[DOMAIN].get('php_fpm'), 'available':available}))
    if inspect:
        for version in (before[DOMAIN]['version'], TARGET):
            info = handler(session, version)
            if isinstance(info, dict):
                info = {key:value for key,value in info.items() if key in ('handler','php_handler','version')}
            elif not isinstance(info, str) or not re.fullmatch(r'[a-z0-9_-]+', info):
                info = 'unknown handler format'
            print('Hosted PHP handler type: ' + json.dumps({'version':version, 'handler':info}))
        for directory in ('/home/' + account, root, deploy.DIRECTORY):
            content = read_file(session, directory, '.htaccess') or ''
            handlers = [line.strip() for line in content.splitlines() if re.match(
                r'\s*(?:AddHandler|SetHandler|FcgidWrapper|Action|ProxyPassMatch)\s+', line)]
            print('Hosted directory PHP handlers: ' + json.dumps({'directory':directory.rsplit('/', 1)[1], 'handlers':handlers}))
        return
    if TARGET not in available:
        raise RuntimeError('PHP 8.4 is not installed on hosting; no settings changed')
    root_before = read_file(session, root, '.htaccess')
    if root_before is None:
        raise RuntimeError('Hosted PHP directory handler backup was unavailable')
    php_handler_content(root_before, TARGET)  # Validate before any settings mutation.
    run = uuid.uuid4().hex
    filename = 'recipe-php-check-' + run + '.php'
    receipt = 'php84-cli-' + run + '.json'
    secret = secrets.token_hex(32)
    source = Path(__file__).with_name('php-runtime-probe.php').read_text().replace(
        '__TOKEN_HASH__', hashlib.sha256(secret.encode()).hexdigest()).replace(
        '__APP_ROOT__', deploy.DIRECTORY).replace('__DATABASE__', account + '_recipes').replace('__RECEIPT__', receipt)
    original = before[DOMAIN]['version']
    source_info = before[DOMAIN].get('phpversion_source', {})
    restore = 'inherit' if source_info.get('system_default') or (
        source_info.get('domain') and source_info['domain'] != DOMAIN) else original
    backup = 'php-upgrade-before-' + run + '.json'
    upload(session, deploy.DIRECTORY + '/storage', backup, json.dumps(before).encode())
    if json.loads(read_file(session, deploy.DIRECTORY + '/storage', backup) or '{}') != before:
        raise RuntimeError('PHP rollback settings backup did not match')
    handler_backup = 'php-handler-before-' + run + '.htaccess'
    upload(session, deploy.DIRECTORY + '/storage', handler_backup, root_before.encode())
    if read_file(session, deploy.DIRECTORY + '/storage', handler_backup) != root_before:
        raise RuntimeError('PHP directory handler backup did not match')
    public = deploy.requests.Session()
    public.headers['User-Agent'] = 'CoffeyTableRecipes PHP upgrade verification'
    probe_url = deploy.PUBLIC + filename
    attempted = False
    uploaded = False
    directory_attempted = False
    stage = 'probe upload'
    try:
        uploaded = True  # An upload timeout may still have written the file.
        upload(session, deploy.DIRECTORY, filename, source.encode())
        if read_file(session, deploy.DIRECTORY, filename) != source:
            raise RuntimeError('Temporary PHP probe did not match')
        if public.get(probe_url, timeout=30).status_code != 404:
            raise RuntimeError('Temporary PHP probe authentication check failed')
        public_checks(public, probe_url, secret, verify_version=False)
        stage = 'hosted CLI checks'
        cli = cli_probe(session, account, filename, secret, receipt)
        print('Hosted PHP CLI checks: ' + json.dumps(check_probe(cli)))
        attempted = True
        stage = 'domain handler API switch'
        set_version(session, TARGET)
        # cPanel updates userdata before failing on this account's stale alias,
        # leaving the actual directory AddHandler behind. Update only that
        # verified site's directive, preserving every other line in the file.
        stage = 'directory handler update'
        current_content = read_file(session, root, '.htaccess')
        if current_content is None:
            raise RuntimeError('Hosted PHP directory handler was unavailable')
        planned_content = php_handler_content(current_content, TARGET)
        if planned_content != current_content:
            directory_attempted = True
            write_handler(session, root, planned_content)
        print('Verified coffeybean.com directory PHP handler: ' + TARGET)
        stage = 'web handler verification'
        # Allow the hosting PHP handler to settle without repeatedly changing it.
        for attempt in range(6):
            try:
                verify_scope(before, versions(session), TARGET)
                web = public_checks(public, probe_url, secret)
                break
            except Exception:
                if attempt == 5:
                    raise
                time.sleep(5)
        print('Hosted PHP web checks: ' + json.dumps(check_probe(web)))
        upload(session, deploy.DIRECTORY + '/storage', 'php-upgrade-after-' + run + '.json',
            json.dumps({'domain':DOMAIN, 'previous':original, 'current':TARGET,
                'cli':check_probe(cli), 'web':check_probe(web), 'other_domains_unchanged':True}).encode())
        print('Verified hosted PHP upgrade: ' + original + ' -> ' + TARGET + '; other domains unchanged.')
    except Exception as error:
        print('PHP upgrade stage failed: ' + stage + ' (' + type(error).__name__ + ')')
        controlled_messages = ('Hosted ', 'Recipe domain PHP setting', 'An unrelated domain PHP setting')
        if isinstance(error, RuntimeError) and str(error).startswith(controlled_messages):
            print('PHP upgrade check detail: ' + str(error))
        if attempted:
            try:
                # A rejected switch may leave the original setting intact.
                current = versions(session)
                if current[DOMAIN]['version'] != original:
                    set_version(session, restore)
                if directory_attempted:
                    write_handler(session, root, root_before)
                verify_scope(before, versions(session), original)
                public_checks(public, probe_url, secret, verify_version=False)
                print('Verified rollback to the previous hosted PHP handler.')
            except Exception:
                raise RuntimeError('Hosted PHP upgrade and rollback verification failed; inspect hosting immediately') from None
        raise RuntimeError('Hosted PHP upgrade checks failed; previous handler retained or restored; details omitted') from None
    finally:
        if uploaded:
            quarantine_probe(session, account, filename)
            if public.get(probe_url, timeout=30).status_code not in (403, 404):
                raise RuntimeError('Temporary PHP probe URL cleanup failed')
            print('Verified temporary PHP probe and cron cleanup.')


if __name__ == '__main__':
    try:
        upgrade(inspect='--inspect' in sys.argv)
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        raise SystemExit('PHP upgrade failed: ' + detail + '. Credentials and recipe contents are not logged.')
