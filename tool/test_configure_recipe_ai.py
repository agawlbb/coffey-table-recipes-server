import importlib.util
import json
import os
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('configure_ai', Path(__file__).with_name('configure-recipe-ai.py'))
setup = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {'requests': types.SimpleNamespace(get=Mock(), Session=Mock())}):
    spec.loader.exec_module(setup)


class KeySetupTests(unittest.TestCase):
    def test_missing_secrets_do_not_contact_network(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(setup.requests, 'get') as request:
            with self.assertRaisesRegex(RuntimeError, 'unavailable'):
                setup.configure()
            request.assert_not_called()

    def test_invalid_account_does_not_contact_network(self):
        with patch.dict(os.environ, {'CPANEL_USER':'../wrong','CPANEL_API_TOKEN':'fixture','RECIPE_AI_API_KEY':'sk-fixture'}, clear=True), patch.object(setup.requests, 'get') as request:
            with self.assertRaisesRegex(RuntimeError, 'unavailable'):
                setup.configure()
            request.assert_not_called()

    def test_failure_does_not_echo_server_details(self):
        response = Mock(status_code=401)
        with self.assertRaisesRegex(RuntimeError, '^Upload failed with HTTP 401; details omitted$'):
            setup.require_success(response, 'Upload')
        response.status_code=200; response.json.return_value={'status':0,'errors':['sensitive response']}
        with self.assertRaisesRegex(RuntimeError, '^Upload was rejected; details omitted$'):
            setup.require_success(response, 'Upload')

    def exercise(self, protection=403, model=200, previous=None, scenario='', error=None):
        home = '/home/hosting_account'
        directory = home + '/coffeybean.com/CoffeyTableRecipes/config'
        target = directory + '/recipe-ai.local.json'
        files = {} if previous is None else {target: previous}
        events = []
        session = Mock()
        session.headers = {}

        def response(data):
            return Mock(status_code=200, json=Mock(return_value=data))

        def get(url, **kwargs):
            params = kwargs['params']
            if url.endswith('list_files'):
                rows = [{'file': path.rsplit('/', 1)[1]} for path in files if path.rsplit('/', 1)[0] == params['dir']]
                return response({'status':1, 'data':rows})
            path = params['dir'] + '/' + params['file']
            content = files[path]
            if scenario == 'mismatch' and path == target and content != previous:
                content = '{}'
            return response({'status':1, 'data':{'content':content}})

        def post(url, **kwargs):
            self.assertFalse(kwargs['allow_redirects'])
            if url.endswith('upload_files'):
                name, content, _ = kwargs['files']['file-1']
                path = kwargs['data']['dir'] + '/' + name
                events.append(('upload', path))
                files[path] = content.decode()
                if scenario == 'timeout' and path == target and content.decode() != previous:
                    raise TimeoutError('sensitive response')
                return response({'status':1})
            data = kwargs['data']
            events.append(('move', data['sourcefiles']))
            self.assertEqual(data['sourcefiles'], target)
            self.assertTrue(data['destfiles'].startswith(home + '/recipe-ai-failed-'))
            if scenario == 'rollback_failure':
                return response({'cpanelresult':{'event':{'result':0},'data':[]}})
            files[data['destfiles']] = files.pop(target)
            return response({'cpanelresult':{'event':{'result':1},'data':[{'result':1,'dest':data['destfiles']}]}})

        statuses = iter(protection if isinstance(protection, tuple) else (protection, protection))
        def public_get(url, **kwargs):
            if url.startswith('https://api.openai.com'):
                return Mock(status_code=model)
            events.append(('protection', None))
            self.assertNotIn('headers', kwargs)
            self.assertFalse(kwargs['allow_redirects'])
            self.assertTrue(kwargs['stream'])
            return Mock(status_code=next(statuses))

        session.get.side_effect = get
        session.post.side_effect = post
        with patch.dict(os.environ, {'CPANEL_USER':'hosting_account','CPANEL_API_TOKEN':'hosting-fixture','RECIPE_AI_API_KEY':'sk-fixture'}, clear=True), \
                patch.object(setup.requests,'get',side_effect=public_get), patch.object(setup.requests,'Session',return_value=session):
            if error:
                with self.assertRaisesRegex(RuntimeError, error) as caught:
                    setup.configure()
                self.assertNotIn('sk-', str(caught.exception))
                self.assertNotIn('sensitive response', str(caught.exception))
            else:
                setup.configure()
        return session, files, events

    def test_success_confines_secret_to_protected_hosting(self):
        session, files, events = self.exercise()
        arguments = session.post.call_args.kwargs
        self.assertEqual(arguments['data']['dir'], '/home/hosting_account/coffeybean.com/CoffeyTableRecipes/config')
        self.assertEqual(arguments['files']['file-1'][0], 'recipe-ai.local.json')
        self.assertFalse(arguments['allow_redirects'])
        self.assertFalse(session.get.call_args.kwargs['allow_redirects'])
        self.assertEqual(events[0], ('protection', None))

    def test_readback_mismatch_fails(self):
        _, files, _ = self.exercise(scenario='mismatch', error='moved outside')
        self.assertFalse(any('/config/' in path for path in files))

    def test_unprotected_config_fails(self):
        for status in (200, 301, 302, 404, 500):
            session, _, _ = self.exercise(protection=status, error='protection failed')
            session.post.assert_not_called()

    def test_invalid_key_fails_before_upload(self):
        session, _, _ = self.exercise(model=401, error='model access failed')
        session.post.assert_not_called()

    def test_postcheck_failure_removes_key(self):
        _, files, _ = self.exercise(protection=(403, 200), error='moved outside')
        self.assertFalse(any('/config/' in path for path in files))

    def test_upload_timeout_still_rolls_back(self):
        _, files, _ = self.exercise(scenario='timeout', error='moved outside')
        self.assertFalse(any('/config/' in path for path in files))

    def test_existing_key_has_private_backup_before_overwrite(self):
        old = '{"api_key":"sk-old","tesseract":"/usr/bin/tesseract"}'
        _, files, events = self.exercise(previous=old)
        uploads = [path for operation, path in events if operation == 'upload']
        self.assertTrue(uploads[0].startswith('/home/hosting_account/recipe-ai-recovery-'))
        self.assertEqual(files[uploads[0]], old)

    def test_previous_key_restored_when_protected(self):
        old = '{"api_key":"sk-old"}'
        _, files, _ = self.exercise(previous=old, scenario='mismatch', protection=(403, 403, 403), error='moved outside')
        self.assertEqual(files['/home/hosting_account/coffeybean.com/CoffeyTableRecipes/config/recipe-ai.local.json'], old)

    def test_old_key_not_restored_into_exposed_directory(self):
        old = '{"api_key":"sk-old"}'
        _, files, _ = self.exercise(previous=old, protection=(403, 200, 200), error='inspect hosted')
        self.assertFalse(any('/config/' in path for path in files))
        self.assertIn(old, files.values())

    def test_restoration_losing_protection_is_quarantined_again(self):
        _, files, events = self.exercise(previous='{"api_key":"sk-old"}', scenario='mismatch', protection=(403, 403, 200), error='inspect hosted')
        self.assertFalse(any('/config/' in path for path in files))
        self.assertEqual(sum(operation == 'move' for operation, _ in events), 2)

    def test_rollback_failure_is_reported(self):
        self.exercise(scenario='rollback_failure', protection=(403, 200), error='inspect hosted')


if __name__ == '__main__':
    unittest.main()
