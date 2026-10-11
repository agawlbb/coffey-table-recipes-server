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

    def test_wrong_account_does_not_contact_network(self):
        with patch.dict(os.environ, {'CPANEL_USER':'wrong','CPANEL_API_TOKEN':'fixture','RECIPE_AI_API_KEY':'sk-fixture'}, clear=True), patch.object(setup.requests, 'get') as request:
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

    def exercise(self, readback=None, protection=403, model=200):
        session = Mock()
        session.headers = {}
        session.post.return_value = Mock(status_code=200)
        session.post.return_value.json.return_value = {'status':1}
        session.get.return_value = Mock(status_code=200)
        session.get.return_value.json.return_value = {'status':1,'data':{'content':json.dumps(readback or {'api_key':'sk-fixture','model':'gpt-4.1-mini'})}}
        public = Mock(side_effect=[Mock(status_code=model), Mock(status_code=protection)])
        with patch.dict(os.environ, {'CPANEL_USER':'hosting_account','CPANEL_API_TOKEN':'hosting-fixture','RECIPE_AI_API_KEY':'sk-fixture'}, clear=True), \
                patch.object(setup.requests,'get',public), patch.object(setup.requests,'Session',return_value=session):
            setup.configure()
        return session, public

    def test_success_confines_secret_to_protected_hosting(self):
        session, public = self.exercise()
        arguments = session.post.call_args.kwargs
        self.assertEqual(arguments['data']['dir'], '/home/hosting_account/coffeybean.com/CoffeyTableRecipes/config')
        self.assertEqual(arguments['files']['file-1'][0], 'recipe-ai.local.json')
        self.assertFalse(arguments['allow_redirects'])
        self.assertFalse(session.get.call_args.kwargs['allow_redirects'])
        self.assertNotIn('headers', public.call_args.kwargs)

    def test_readback_mismatch_fails(self):
        with self.assertRaisesRegex(RuntimeError, 'did not match'):
            self.exercise(readback={'api_key':'wrong'})

    def test_unprotected_config_fails(self):
        with self.assertRaisesRegex(RuntimeError, 'protection failed'):
            self.exercise(protection=200)

    def test_invalid_key_fails_before_upload(self):
        with self.assertRaisesRegex(RuntimeError, 'model access failed'):
            self.exercise(model=401)


if __name__ == '__main__':
    unittest.main()
