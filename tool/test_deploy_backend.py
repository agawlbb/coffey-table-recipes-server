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
    def test_missing_secrets_stop_before_packaging_or_network(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(deploy, 'runtime_files') as package:
            with self.assertRaisesRegex(RuntimeError, 'repository secrets'):
                deploy.deploy()
            package.assert_not_called()

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

    def test_http_failure_reports_only_operation_and_status(self):
        response = Mock(status_code=401)
        response.raise_for_status.side_effect = deploy.requests.exceptions.HTTPError('private request details')
        with self.assertRaisesRegex(RuntimeError, '^Upload failed with HTTP 401; response details omitted$'):
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

    def test_stale_runtime_source_does_not_publish_receipt(self):
        self.run_deployment_case('stale_source')

    def test_matching_runtime_source_is_verified(self):
        self.run_deployment_case('verified_source')

    def run_deployment_case(self, scenario):
        original = Path.cwd()
        with tempfile.TemporaryDirectory() as directory:
            os.chdir(directory)
            try:
                Path('storage').mkdir()
                Path('index.php').write_text('<?php')
                Path('src').mkdir()
                Path('src/RecipeVariationService.php').write_text('<?php // variation')
                private = Mock(headers={})
                public = Mock(headers={})
                uploaded = Mock()
                uploaded.json.return_value = {'status': int(scenario != 'upload')}
                extracted = Mock()
                extracted.json.return_value = {'cpanelresult': {'event': {'result': 1},
                    'data': [{'result': 1, 'dest': '/wrong' if scenario == 'destination' else deploy.DIRECTORY}]}}
                private.post.side_effect = [uploaded, extracted, uploaded]
                source = Mock()
                source.json.return_value = {'status': 1, 'data': {'content': '<?php // stale' if scenario == 'stale_source' else '<?php // variation'}}
                private.get.return_value = source
                health = Mock()
                health.json.return_value = {'status': 'failed' if scenario == 'health' else 'ok'}
                forbidden = Mock(status_code=403)
                receipt = Mock()
                receipt.json.side_effect = lambda: json.loads(private.post.call_args.kwargs['files']['file-1'][1])
                public.get.side_effect = [health, forbidden, forbidden, forbidden, Mock(headers={'Access-Control-Allow-Origin': '*'}), receipt]
                public.options.return_value = Mock(status_code=204,
                    headers={'Access-Control-Allow-Origin': 'http://localhost'})
                with patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-secret'), \
                        patch.object(deploy.subprocess, 'check_output', return_value=b'a' * 40), \
                        patch.object(deploy, 'runtime_files', return_value=[Path('index.php')] + ([Path('src/RecipeVariationService.php')] if scenario in ('stale_source','verified_source') else [])), \
                        patch.object(deploy.requests, 'Session', side_effect=[private, public]):
                    if scenario in ('success','verified_source'):
                        deploy.deploy()
                        self.assertEqual(private.post.call_count, 3)
                        self.assertEqual(public.get.call_count, 6)
                        self.assertNotIn('Authorization', public.headers)
                        self.assertTrue(private.post.call_args_list[0].kwargs['data']['dir'].endswith('/storage'))
                        if scenario == 'verified_source':
                            private.get.assert_called_once()
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
