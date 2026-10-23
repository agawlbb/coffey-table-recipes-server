"""Add the explicitly authorized LAN origin without exposing private configuration."""
import os
import re
import time
import uuid

ORIGIN = 'http://192.168.12.194'
HOST = 'https://ecngx362.inmotionhosting.com:2083'
PUBLIC = 'https://coffeybean.com/CoffeyTableRecipes/'


def verify_origin(public, origin, method, headers):
    # PHP workers may briefly retain the previous config after atomic readback.
    for attempt in range(10):
        response = public.request(method, PUBLIC + 'api/v1/health',
            headers=headers, timeout=45, allow_redirects=False)
        if response.status_code == (204 if method == 'OPTIONS' else 200) and response.headers.get('Access-Control-Allow-Origin') == origin:
            return
        if attempt < 9:
            time.sleep(1)
    raise RuntimeError('Public origin verification failed: ' + origin + ' ' + method)


def amended(source):
    pattern = r"(['\"])allowed_origins\1\s*=>\s*\[([^\[\]]*)\]"
    matches = list(re.finditer(pattern, source))
    if len(matches) != 1:
        raise RuntimeError('Expected exactly one literal allowed_origins array')
    match = matches[0]
    body = match.group(2)
    # Accept only literal HTTP(S) origins; reject expressions/comments rather than
    # attempting to reinterpret arbitrary PHP or overwrite unrelated settings.
    literal = r"(['\"])(https?://[A-Za-z0-9.:[\]-]+)\1"
    entries = list(re.finditer(literal, body))
    residue = re.sub(literal, '', body)
    if re.sub(r'[\s,]', '', residue):
        raise RuntimeError('Unsupported allowed_origins syntax')
    if entries:
        gaps = [body[:entries[0].start()]]
        gaps += [body[a.end():b.start()] for a, b in zip(entries, entries[1:])]
        if gaps[0].strip() or any(g.strip() != ',' for g in gaps[1:]):
            raise RuntimeError('Invalid literal origin list')
    if ORIGIN in [entry.group(2) for entry in entries]:
        return source
    prefix = body.rstrip()
    separator = '' if not prefix or prefix.endswith(',') else ','
    replacement = prefix + separator + " '" + ORIGIN + "'" + body[len(prefix):]
    return source[:match.start(2)] + replacement + source[match.end(2):]


def checked(response):
    if response.status_code != 200:
        raise RuntimeError('Hosting operation failed; private details omitted')
    result = response.json()
    if result.get('status') != 1:
        raise RuntimeError('Hosting operation rejected; private details omitted')
    return result


def configure():
    import requests
    user = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not re.fullmatch(r'[A-Za-z0-9_]+', user) or not token:
        raise RuntimeError('Hosting credentials unavailable')
    directory = f'/home/{user}/coffeybean.com/CoffeyTableRecipes/config'
    session = requests.Session()
    session.headers['Authorization'] = f'cpanel {user}:{token}'

    def read(name):
        data = checked(session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': directory, 'file': name}, timeout=30,
            allow_redirects=False))
        content = data.get('data', {}).get('content')
        if not isinstance(content, str):
            raise RuntimeError('Private configuration read incomplete')
        return content

    def upload(name, content):
        checked(session.post(HOST + '/execute/Fileman/upload_files',
            data={'dir': directory, 'overwrite': '1'},
            files={'file-1': (name, content.encode('utf-8'), 'application/octet-stream')},
            timeout=60, allow_redirects=False))
        permission = session.post(HOST + '/json-api/cpanel', data={
            'cpanel_jsonapi_user': user, 'cpanel_jsonapi_apiversion': '2',
            'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop',
            'op': 'chmod', 'sourcefiles': directory + '/' + name,
            'metadata': '0600', 'doubledecode': '1'}, timeout=30, allow_redirects=False)
        if permission.status_code != 200:
            raise RuntimeError('Private file permissions failed')
        result = permission.json().get('cpanelresult', {})
        rows = result.get('data', [])
        if result.get('event', {}).get('result') != 1 or not rows or any(row.get('result') != 1 for row in rows):
            raise RuntimeError('Private file permissions rejected; details omitted')
        if read(name) != content:
            raise RuntimeError('Private configuration readback mismatch')

    public = requests.Session()
    public.headers['User-Agent'] = 'Mozilla/5.0 CTR origin verification'
    if public.get(PUBLIC + 'config/local.php', timeout=30,
                  allow_redirects=False).status_code != 403:
        raise RuntimeError('Private configuration protection check failed')
    original = read('local.php')
    updated = amended(original)
    if updated != original:
        upload('local-origin-backup-' + uuid.uuid4().hex + '.php', original)
        if read('local.php') != original:
            raise RuntimeError('Private configuration changed concurrently; stopped')
        upload('local.php', updated)
    for origin in (ORIGIN, 'http://localhost'):
        for method in ('GET', 'OPTIONS'):
            headers = {'Origin': origin}
            if method == 'OPTIONS':
                headers.update({'Access-Control-Request-Method': 'GET',
                                'Access-Control-Request-Headers': 'authorization,content-type'})
            verify_origin(public, origin, method, headers)
    print('Exact LAN origin installed; LAN and localhost GET/OPTIONS verified; private config preserved.')


if __name__ == '__main__':
    configure()
