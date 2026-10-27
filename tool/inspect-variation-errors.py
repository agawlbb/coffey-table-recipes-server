"""Read hosted logs through cPanel; emit only allowlisted diagnostic fields."""
import collections
import os
import re
import sys

HOST = 'https://ecngx362.inmotionhosting.com:2083'


def summarize(text):
    rows = []
    for line in text.splitlines():
        if '27-Oct-2026' not in line and '27/Oct/2026' not in line:
            continue
        stamp = re.search(r'\[([^\]]{1,80})\]', line)
        timestamp = stamp[1] if stamp and re.fullmatch(r'[\w /:+.-]+', stamp[1]) else 'unknown'
        failure = re.search(r'Recipe AI failure: HTTP (\d{1,3}), transport (\d{1,3}), code ([a-z_]{0,80})', line)
        if failure:
            rows.append((timestamp, 'provider', *failure.groups()))
        elif 'Maximum execution time' in line:
            rows.append((timestamp, 'php_execution_timeout'))
        elif 'RecipeVariationService' in line and ('Fatal' in line or 'Exception' in line):
            rows.append((timestamp, 'variation_exception'))
        elif re.search(r'"POST /CoffeyTableRecipes/api/v1/households/\d+/recipes/\d+/variation(?:[ ?])', line):
            status = re.search(r'" (\d{3}) ', line)
            rows.append((timestamp, 'variation_http', status[1] if status else 'unknown'))
    return rows


def inspect():
    import requests

    user, token = os.environ.get('CPANEL_USER'), os.environ.get('CPANEL_API_TOKEN')
    if not user or not token:
        raise RuntimeError('Hosting credentials unavailable')
    session = requests.Session()
    session.headers['Authorization'] = f'cpanel {user}:{token}'
    root = f'/home/{user}'
    paths = [
        (root + '/coffeybean.com/CoffeyTableRecipes', 'error_log'),
        (root + '/coffeybean.com', 'error_log'),
        (root + '/logs', 'error_log'),
        (root + '/access-logs', 'coffeybean.com-ssl_log'),
        (root + '/access-logs', 'coffeybean.com'),
    ]
    for index, (directory, name) in enumerate(paths):
        response = session.get(HOST + '/execute/Fileman/get_file_content',
            params={'dir': directory, 'file': name}, timeout=30, allow_redirects=False)
        if response.status_code != 200:
            print(f'Log source {index}: HTTP {response.status_code}')
            continue
        result = response.json()
        content = result.get('data', {}).get('content') if result.get('status') == 1 else None
        if not isinstance(content, str):
            print(f'Log source {index}: unavailable')
            continue
        rows = summarize(content)
        print(f'Log source {index}: readable; matching diagnostics={len(rows)}')
        for row, count in collections.Counter(rows).items():
            print(' | '.join(row) + f' | count={count}')


if __name__ == '__main__':
    try:
        inspect()
    except Exception as error:
        print('Read-only log inspection failed: ' + type(error).__name__, file=sys.stderr)
        sys.exit(1)
