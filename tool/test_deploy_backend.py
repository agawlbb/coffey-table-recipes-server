import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import os
import json

spec = importlib.util.spec_from_file_location('deploy_backend', Path(__file__).with_name('deploy-backend.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class DeploymentTests(unittest.TestCase):
    def test_package_excludes_credentials_media_and_frontend(self):
        original = Path.cwd()
        with tempfile.TemporaryDirectory() as directory:
            os.chdir(directory)
            try:
                Path('vendor').mkdir()
                Path('vendor/autoload.php').write_text('<?php')
                names = ['index.php', 'src/RecipeService.php', 'config/local.php',
                         'config/smtp.local.json', 'app/main.dart.js',
                         'media/recipes/photo.jpg', 'storage/private.sql.dump',
                         'tool/deploy-backend.py', '.github/workflows/deploy-backend.yml']
                with patch.object(deploy.subprocess, 'check_output', return_value='\0'.join(names).encode()):
                    self.assertEqual([p.as_posix() for p in deploy.runtime_files()],
                                     ['index.php', 'src/RecipeService.php', 'vendor/autoload.php'])
            finally:
                os.chdir(original)

    def test_missing_dependencies_stop_packaging(self):
        with patch.object(deploy.subprocess, 'check_output', return_value=b'index.php'), \
                patch.object(Path, 'is_file', return_value=False):
            with self.assertRaises(RuntimeError):
                deploy.runtime_files()

    def test_failed_upload_is_rejected_without_response_details(self):
        response = Mock()
        response.json.return_value = {'status': 0, 'errors': ['private details']}
        with self.assertRaisesRegex(RuntimeError, '^Upload failed; response details omitted$'):
            deploy.require_success(response, 'Upload')

    def test_symlink_rejected(self):
        with patch.object(deploy.subprocess, 'check_output', return_value=b'index.php'), \
                patch.object(Path, 'is_file', return_value=True), \
                patch.object(Path, 'is_symlink', return_value=True):
            with self.assertRaisesRegex(RuntimeError, 'symbolic link'):
                deploy.runtime_files()

    def test_success_keeps_credentials_out_of_public_checks(self):
        self.run_deployment_case('success')

    def test_upload_failure_stops_before_extraction(self):
        self.run_deployment_case('upload')

    def test_wrong_extraction_destination_stops_checks(self):
        self.run_deployment_case('destination')

    def test_failed_health_does_not_publish_receipt(self):
        self.run_deployment_case('health')

    def run_deployment_case(self, scenario):
        original = Path.cwd()
        with tempfile.TemporaryDirectory() as directory:
            os.chdir(directory)
            try:
                Path('storage').mkdir()
                Path('index.php').write_text('<?php')
                private = Mock(headers={})
                public = Mock(headers={})
                uploaded = Mock()
                uploaded.json.return_value = {'status': int(scenario != 'upload')}
                extracted = Mock()
                extracted.json.return_value = {'cpanelresult': {'event': {'result': 1},
                    'data': [{'result': 1, 'dest': '/wrong' if scenario == 'destination' else deploy.DIRECTORY}]}}
                private.post.side_effect = [uploaded, extracted, uploaded]
                health = Mock()
                health.json.return_value = {'status': 'failed' if scenario == 'health' else 'ok'}
                forbidden = Mock(status_code=403)
                receipt = Mock()
                receipt.json.side_effect = lambda: json.loads(private.post.call_args.kwargs['files']['file-1'][1])
                public.get.side_effect = [health, forbidden, forbidden, forbidden, receipt]
                public.options.return_value = Mock(status_code=204,
                    headers={'Access-Control-Allow-Origin': 'http://localhost'})
                with patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-secret'), \
                        patch.object(deploy.subprocess, 'check_output', return_value=b'a' * 40), \
                        patch.object(deploy, 'runtime_files', return_value=[Path('index.php')]), \
                        patch.object(deploy.requests, 'Session', side_effect=[private, public]):
                    if scenario == 'success':
                        deploy.deploy()
                        self.assertEqual(private.post.call_count, 3)
                        self.assertEqual(public.get.call_count, 5)
                        self.assertNotIn('Authorization', public.headers)
                        self.assertTrue(private.post.call_args_list[0].kwargs['data']['dir'].endswith('/storage'))
                    else:
                        with self.assertRaises(RuntimeError):
                            deploy.deploy()
                        self.assertEqual(private.post.call_count, 1 if scenario == 'upload' else 2)
                        if scenario != 'health':
                            public.get.assert_not_called()
            finally:
                os.chdir(original)


if __name__ == '__main__':
    unittest.main()
