import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('ha_config', Path(__file__).with_name('configure-home-assistant.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class HomeAssistantConfigTests(unittest.TestCase):
    env = {'CPANEL_USER':'fixture', 'CPANEL_API_TOKEN':'fixture-token', 'FIREBASE_WEB_API_KEY':'AIza'+'x'*35}

    def test_rejects_invalid_destination_before_network(self):
        with patch.dict(module.os.environ, {**self.env, 'CPANEL_USER':'../other'}, clear=True), patch.object(module.requests, 'get') as get:
            with self.assertRaises(RuntimeError): module.configure()
            get.assert_not_called()

    def test_requires_protected_directory(self):
        with patch.dict(module.os.environ, self.env, clear=True), patch.object(module.requests, 'get', return_value=Mock(status_code=200)), patch.object(module.requests, 'Session') as session:
            with self.assertRaises(RuntimeError): module.configure()
            session.assert_not_called()

    def test_upload_and_readback_exact_public_key(self):
        session = Mock(headers={})
        session.post.return_value = Mock(status_code=200, json=lambda:{'status':1})
        session.get.return_value = Mock(status_code=200, json=lambda:{'status':1,'data':{'content':json.dumps({'api_key':self.env['FIREBASE_WEB_API_KEY']})}})
        with patch.dict(module.os.environ, self.env, clear=True), patch.object(module.requests, 'get', return_value=Mock(status_code=403)), patch.object(module.requests, 'Session', return_value=session):
            module.configure()
        self.assertEqual(session.post.call_args.kwargs['data']['dir'], '/home/fixture/coffeybean.com/CoffeyTableRecipes/config')
        self.assertEqual(session.post.call_args.kwargs['files']['file-1'][0], 'home-assistant.local.json')

    def test_rejects_failed_upload(self):
        session = Mock(headers={})
        session.post.return_value = Mock(status_code=401)
        with patch.dict(module.os.environ, self.env, clear=True), patch.object(module.requests, 'get', return_value=Mock(status_code=403)), patch.object(module.requests, 'Session', return_value=session):
            with self.assertRaises(RuntimeError): module.configure()
        session.get.assert_not_called()


if __name__ == '__main__': unittest.main()
