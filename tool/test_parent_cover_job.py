import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('parent_cover_job', Path(__file__).with_name('cover-homemade-ingredients.py'))
cover = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cover)


class ParentCoverJobTests(unittest.TestCase):
    def test_missing_credentials_stop_before_scheduling(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(cover.jobs, 'cron') as cron:
            with self.assertRaisesRegex(RuntimeError, 'Hosting credentials'):
                cover.generate()
            cron.assert_not_called()

    def run_case(self, scenario):
        original = Path.cwd()
        with tempfile.TemporaryDirectory() as directory:
            os.chdir(directory)
            try:
                Path('storage').mkdir()
                image = b'fixture image'
                receipt = {'status': 'ok', 'category_id': '7', 'label': 'Homemade ingredients',
                           'image_url': cover.deploy.PUBLIC + 'media/recipes/category-7.jpg',
                           'sha256': hashlib.sha256(image).hexdigest()}
                if scenario == 'error':
                    receipt = {'status': 'error'}
                if scenario == 'wrong_destination':
                    receipt['image_url'] = 'https://elsewhere.test/photo.jpg'
                if scenario == 'hash':
                    receipt['sha256'] = 'wrong'
                response = Mock(status_code=200)
                response.json.return_value = {'status': 1, 'data': {'content': json.dumps(receipt)}}
                session = Mock(headers={})
                session.get.return_value = response
                public = Mock(status_code=200, content=image)
                with patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='not-a-real-token'), \
                        patch.object(cover.deploy.requests, 'Session', return_value=session), \
                        patch.object(cover.deploy.requests, 'get', return_value=public) as get, \
                        patch.object(cover.jobs, 'cron') as cron, \
                        patch.object(cover.jobs, 'remove_job') as remove:
                    if scenario == 'ok':
                        cover.generate()
                        self.assertTrue(Path('storage/parent-cover-report.json').is_file())
                    else:
                        with self.assertRaises(RuntimeError):
                            cover.generate()
                    remove.assert_called_once()
                    self.assertEqual(remove.call_args.args[1], cron.call_args.kwargs['command'])
                    self.assertIn('/bin/cover-homemade-ingredients.php', remove.call_args.args[1])
                    if scenario in ('error', 'wrong_destination'):
                        get.assert_not_called()
            finally:
                os.chdir(original)

    def test_verified_cover_is_recorded_and_temporary_cron_removed(self):
        self.run_case('ok')

    def test_failed_generation_removes_temporary_cron(self):
        self.run_case('error')

    def test_wrong_destination_is_not_downloaded(self):
        self.run_case('wrong_destination')

    def test_public_hash_mismatch_fails(self):
        self.run_case('hash')


if __name__ == '__main__':
    unittest.main()
