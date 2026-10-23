"""Verify route readiness without credentials or mutations after PHP publication."""
import datetime
import time

import requests


def verify(session=None, wait=time.sleep):
    session = session or requests.Session()
    day = datetime.date.today().isoformat()
    url = 'https://coffeybean.com/CoffeyTableRecipes/api/v1/households/1/meal-plan'
    for attempt in range(7):
        response = session.get(url, params={'from': day, 'to': day}, timeout=60)
        if response.status_code == 401:
            if response.json().get('error', {}).get('code') != 'authentication_required':
                raise RuntimeError('Unexpected authentication response')
            print('Verified hosted meal planner route requires authentication.')
            return
        # During a PHP/OPcache handover the old router may briefly return 404.
        # Never accept a 200 or retry a response exposing unauthenticated content.
        if response.status_code not in (404, 502, 503, 504) or attempt == 6:
            raise RuntimeError(f'Meal planner route readiness failed (HTTP {response.status_code})')
        wait(5)


if __name__ == '__main__':
    verify()
