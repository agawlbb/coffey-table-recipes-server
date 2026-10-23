import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('local_origin', Path(__file__).with_name('configure-local-web-origin.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class OriginTests(unittest.TestCase):
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
