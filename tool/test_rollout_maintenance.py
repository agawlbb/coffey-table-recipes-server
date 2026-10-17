from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

PHP = os.environ.get('ROLLOUT_PHP_BINARY') or shutil.which('php')
ROOT = Path(__file__).resolve().parent.parent


@unittest.skipUnless(PHP, 'PHP CLI required for maintenance entrypoint checks')
class MaintenanceTests(unittest.TestCase):
    def test_purge_marker_exits_before_loading_database_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'bin').mkdir(); (root / 'storage').mkdir()
            script = root / 'bin/purge-deleted-recipes.php'
            script.write_bytes((ROOT / 'bin/purge-deleted-recipes.php').read_bytes())
            (root / 'storage/shared-rollout-maintenance').write_text('fixture')
            # No bootstrap or config exists: any attempt to load it fails this test.
            result = subprocess.run([PHP, str(script)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('purge remains paused', result.stderr)

    def test_wrong_host_target_stops_before_configuration_or_database(self):
        result = subprocess.run([PHP, str(ROOT / 'bin/shared-recipe-rollout.php'), 'apply', 'fixture'],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Invalid phase or deployment target', result.stderr)


if __name__ == '__main__':
    unittest.main()
