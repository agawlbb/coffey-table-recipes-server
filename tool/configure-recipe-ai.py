"""Install a server-only key using the already verified deployment credentials."""
import json
import os
import sys
import uuid

import requests

HOST = 'https://ecngx362.inmotionhosting.com:2083'
PUBLIC = 'https://coffeybean.com/CoffeyTableRecipes/'


def require_success(response, operation):
    if response.status_code != 200:
        raise RuntimeError(f'{operation} failed with HTTP {response.status_code}; details omitted')
    data = response.json()
    if data.get('status') != 1:
        raise RuntimeError(operation + ' was rejected; details omitted')
    return data


def configure():
    key = os.environ.get('RECIPE_AI_API_KEY', '')
    user = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not user or not user.replace('_', '').isalnum() or not token or not key.startswith('sk-'):
        raise RuntimeError('Required secure setup credentials are unavailable')
    # Validate the key without modifying OpenAI resources or consuming model tokens.
    response = requests.get('https://api.openai.com/v1/models/gpt-5.4-mini',
                            headers={'Authorization': 'Bearer ' + key}, timeout=30)
    if response.status_code != 200:
        raise RuntimeError(f'OpenAI model access failed with HTTP {response.status_code}; details omitted')
    payload = {'api_key': key, 'model': 'gpt-5.4-mini'}
    session = requests.Session()
    session.headers['Authorization'] = f'cpanel {user}:{token}'
    directory = f'/home/{user}/coffeybean.com/CoffeyTableRecipes/config'
    home = f'/home/{user}'

    def protected():
        # Do not download a possibly exposed secret or follow a login redirect.
        response = requests.get(PUBLIC + 'config/recipe-ai.local.json',
                                timeout=30, allow_redirects=False, stream=True)
        try:
            if response.status_code != 403:
                raise RuntimeError('Public key-file access protection failed')
        finally:
            response.close()

    def upload(target, filename, content):
        require_success(session.post(HOST + '/execute/Fileman/upload_files',
            data={'dir': target, 'overwrite': '1'}, files={
                'file-1': (filename, content, 'application/json')},
            timeout=60, allow_redirects=False), 'Protected key upload')

    def readback(target, filename):
        result = require_success(session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': target, 'file': filename},
            timeout=30, allow_redirects=False), 'Protected key readback')
        content = result.get('data', {}).get('content')
        if not isinstance(content, str):
            raise RuntimeError('Protected key readback was incomplete')
        return content

    def quarantine():
        destination = home + '/recipe-ai-failed-' + uuid.uuid4().hex + '.json'
        response = session.post(HOST + '/json-api/cpanel', data={
            'cpanel_jsonapi_user': user, 'cpanel_jsonapi_apiversion': '2',
            'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop',
            'op': 'move', 'sourcefiles': directory + '/recipe-ai.local.json',
            'destfiles': destination, 'doubledecode': '1'},
            timeout=60, allow_redirects=False)
        result = response.json().get('cpanelresult', {})
        items = result.get('data', [])
        if response.status_code != 200 or result.get('event', {}).get('result') != 1 or not items or any(
                item.get('result') != 1 or item.get('dest') not in (home, destination) for item in items):
            raise RuntimeError('Key rollback could not be verified')
        # Verify the moved file exists privately and the public-tree file is gone.
        readback(home, destination.rsplit('/', 1)[1])
        listing = require_success(session.get(HOST + '/execute/Fileman/list_files',
            params={'dir': directory}, timeout=30, allow_redirects=False), 'Rollback listing')
        rows = listing.get('data')
        if not isinstance(rows, list) or any(not isinstance(row, dict) or not isinstance(row.get('file'), str)
                or row['file'] == 'recipe-ai.local.json' for row in rows):
            raise RuntimeError('Key rollback could not be verified')

    protected()
    listing = require_success(session.get(HOST + '/execute/Fileman/list_files',
        params={'dir': directory}, timeout=30, allow_redirects=False), 'Configuration listing')
    rows = listing.get('data')
    if not isinstance(rows, list) or any(not isinstance(row, dict) or not isinstance(row.get('file'), str) for row in rows):
        raise RuntimeError('Configuration listing was incomplete')
    previous = readback(directory, 'recipe-ai.local.json') if any(
        row['file'] == 'recipe-ai.local.json' for row in rows) else None
    if previous is not None:
        prior_configuration = json.loads(previous)
        if not isinstance(prior_configuration, dict):
            raise RuntimeError('Existing key configuration was invalid; no changes made')
        # Preserve private extractor settings when replacing only the API key.
        payload = {**prior_configuration, **payload}
    recovery = 'recipe-ai-recovery-' + uuid.uuid4().hex + '.json'
    if previous is not None:
        # Verified recovery copy outside all website roots, before any overwrite.
        upload(home, recovery, previous.encode())
        if readback(home, recovery) != previous:
            raise RuntimeError('Private key recovery copy did not match')
    try:
        upload(directory, 'recipe-ai.local.json', json.dumps(payload).encode())
        if json.loads(readback(directory, 'recipe-ai.local.json')) != payload:
            raise RuntimeError('Installed key configuration did not match')
        protected()
    except Exception:
        # A timeout may have occurred after the upload completed. Always attempt
        # to remove the possibly installed key from the publicly served tree.
        try:
            quarantine()
            if previous is not None:
                protected()  # Never restore a prior secret into an exposed directory.
                try:
                    upload(directory, 'recipe-ai.local.json', previous.encode())
                    if readback(directory, 'recipe-ai.local.json') != previous:
                        raise RuntimeError('Previous key restoration did not match')
                    protected()
                except Exception:
                    quarantine()
                    raise
        except Exception:
            raise RuntimeError('Key rollback or restoration could not be verified; inspect hosted configuration immediately') from None
        raise RuntimeError('Key installation failed; uploaded key moved outside the web directory') from None
    print('Verified OpenAI model access and protected hosted key installation.')


if __name__ == '__main__':
    try:
        configure()
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        print('Key setup failed: ' + detail + '. Secret values are never logged.', file=sys.stderr)
        sys.exit(1)
