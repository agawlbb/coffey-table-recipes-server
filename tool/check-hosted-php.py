"""Read only the coffeybean.com PHP handler from authenticated cPanel."""
import os
import re
import sys

import requests

HOST = 'https://ecngx362.inmotionhosting.com:2083'


def selected_version(result):
    if result.get('status') != 1 or not isinstance(result.get('data'), list):
        raise RuntimeError('Hosted PHP version query failed; details omitted')
    rows = [row for row in result['data'] if row.get('vhost') == 'coffeybean.com']
    if len(rows) != 1 or not re.fullmatch(r'(?:ea|alt)-php[0-9]{2,3}', rows[0].get('version', '')):
        raise RuntimeError('Hosted PHP handler could not be resolved for coffeybean.com')
    return rows[0]['version']


def check():
    user = os.environ.get('CPANEL_USER', '')
    token = os.environ.get('CPANEL_API_TOKEN', '')
    if not user or not token:
        raise RuntimeError('Hosting credentials unavailable')
    response = requests.get(HOST + '/execute/LangPHP/php_get_vhost_versions',
        headers={'Authorization': f'cpanel {user}:{token}'},
        timeout=30, allow_redirects=False)
    if response.status_code != 200:
        raise RuntimeError('Hosted PHP query failed; details omitted')
    version = selected_version(response.json())
    print('coffeybean.com configured PHP handler: ' + version)
    branch = int(version.rsplit('php', 1)[1])
    if branch <= 81:
        print('This PHP branch has ended upstream security support; prioritize a tested hosting upgrade.')
    elif branch == 82:
        print('PHP 8.2 security support ends 2026-12-31; test a supported upgrade before then.')


if __name__ == '__main__':
    try:
        check()
    except Exception as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        print('PHP version check failed: ' + detail, file=sys.stderr)
        sys.exit(1)
