"""Install a server-only key using the already verified deployment credentials."""
import json
import os
import sys

import requests

HOST = 'https://ecngx362.inmotionhosting.com:2083'
DIRECTORY = '/home/hosting_account/coffeybean.com/CoffeyTableRecipes/config'
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
    if user != 'hosting_account' or not token or not key.startswith('sk-'):
        raise RuntimeError('Required secure setup credentials are unavailable')
    # Validate the key without modifying OpenAI resources or consuming model tokens.
    response = requests.get('https://api.openai.com/v1/models/gpt-5.4-mini',
                            headers={'Authorization': 'Bearer ' + key}, timeout=30)
    if response.status_code != 200:
        raise RuntimeError(f'OpenAI model access failed with HTTP {response.status_code}; details omitted')
    payload = {'api_key': key, 'model': 'gpt-5.4-mini'}
    session = requests.Session()
    session.headers['Authorization'] = f'cpanel {user}:{token}'
    require_success(session.post(HOST + '/execute/Fileman/upload_files',
        data={'dir': DIRECTORY, 'overwrite': '1'}, files={
            'file-1': ('recipe-ai.local.json', json.dumps(payload).encode(), 'application/json')},
        timeout=60, allow_redirects=False), 'Protected key upload')
    result = require_success(session.get(HOST + '/execute/Fileman/get_file_content',
        params={'dir': DIRECTORY, 'file': 'recipe-ai.local.json'},
        timeout=30, allow_redirects=False), 'Protected key readback')
    # Never print a cPanel response, key, or exception containing response content.
    if json.loads(result.get('data', {}).get('content', '{}')) != payload:
        raise RuntimeError('Installed key configuration did not match')
    response = requests.get(PUBLIC + 'config/recipe-ai.local.json', timeout=30)
    if response.status_code != 403:
        raise RuntimeError('Public key-file access protection failed')
    print('Verified OpenAI model access and protected hosted key installation.')


if __name__ == '__main__':
    try:
        configure()
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        print('Key setup failed: ' + detail + '. Secret values are never logged.', file=sys.stderr)
        sys.exit(1)
