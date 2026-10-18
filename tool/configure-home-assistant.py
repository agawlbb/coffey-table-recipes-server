"""Install public Firebase client identifier without putting local config in Git."""
import json
import os
import re
import requests


def configure():
    user = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    key = os.environ.get('FIREBASE_WEB_API_KEY', '')
    if not re.fullmatch(r'[A-Za-z0-9_]+', user) or not token or not re.fullmatch(r'AIza[A-Za-z0-9_-]{30,50}', key):
        raise RuntimeError('Home Assistant configuration credentials unavailable')
    public = 'https://coffeybean.com/CoffeyTableRecipes/'
    response = requests.get(public+'config/home-assistant.local.json', timeout=30, allow_redirects=False)
    if response.status_code != 403:
        raise RuntimeError('Configuration directory protection failed')
    session = requests.Session()
    session.headers['Authorization'] = f'cpanel {user}:{token}'
    directory = f'/home/{user}/coffeybean.com/CoffeyTableRecipes/config'
    host = 'https://ecngx362.inmotionhosting.com:2083'
    payload = {'api_key': key}
    response = session.post(host+'/execute/Fileman/upload_files',
        data={'dir': directory, 'overwrite':'1'},
        files={'file-1': ('home-assistant.local.json', json.dumps(payload).encode(), 'application/json')},
        timeout=60, allow_redirects=False)
    if response.status_code != 200 or response.json().get('status') != 1:
        raise RuntimeError('Home Assistant config upload failed; details omitted')
    response = session.get(host+'/execute/Fileman/get_file_content',
        params={'dir':directory, 'file':'home-assistant.local.json'}, timeout=30, allow_redirects=False)
    if response.status_code != 200 or response.json().get('status') != 1 or json.loads(response.json()['data']['content']) != payload:
        raise RuntimeError('Home Assistant config verification failed; details omitted')
    print('Home Assistant public client configuration installed and verified')


if __name__ == '__main__':
    configure()
