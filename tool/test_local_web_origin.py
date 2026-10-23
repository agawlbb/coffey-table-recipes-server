import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('local_origin', Path(__file__).with_name('configure-local-web-origin.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class OriginTests(unittest.TestCase):
    def test_public_verification_retries_stale_config_but_is_bounded(self):
        session = Mock()
        stale = Mock(status_code=200, headers={})
        ready = Mock(status_code=200, headers={'Access-Control-Allow-Origin': module.ORIGIN})
        session.request.side_effect = [stale, ready]
        with patch.object(module.time, 'sleep'):
            module.verify_origin(session, module.ORIGIN, 'GET', {'Origin': module.ORIGIN})
        self.assertEqual(session.request.call_count, 2)
        session.request.side_effect = None
        session.request.return_value = stale
        session.request.reset_mock()
        with patch.object(module.time, 'sleep'), self.assertRaises(RuntimeError):
            module.verify_origin(session, module.ORIGIN, 'GET', {})
        self.assertEqual(session.request.call_count, 10)

    def test_preserves_other_configuration_and_idempotence(self):
        for body in ("'http://localhost'", "'http://localhost',\n", ''):
            source = "<?php return ['password'=>'fixture', 'allowed_origins' => [" + body + "], 'other'=>true];"
            result = module.amended(source)
            self.assertIn(module.ORIGIN, result)
            self.assertTrue(result.startswith("<?php return ['password'=>'fixture', 'allowed_origins' => ["))
            self.assertTrue(result.endswith("], 'other'=>true];"))
            self.assertEqual(module.amended(result), result)
            self.assertEqual(result.count('http://localhost'), source.count('http://localhost'))

    def test_rejects_dynamic_or_ambiguous_configuration(self):
        for source in ("'allowed_origins'=>getenv('ORIGINS')", "'allowed_origins'=>[$variable]",
                       "'allowed_origins'=>[], 'allowed_origins'=>[]",
                       "'allowed_origins'=>['http://localhost' 'https://example.com']"):
            with self.assertRaises(RuntimeError):
                module.amended(source)


if __name__ == '__main__':
    unittest.main()
