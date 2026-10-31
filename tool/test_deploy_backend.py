import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('deploy', Path(__file__).with_name('deploy-backend.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

class RetirementTests(unittest.TestCase):
    def test_no_secrets_means_no_network(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(deploy.requests, 'Session') as session:
            with self.assertRaisesRegex(RuntimeError, 'repository secrets'):
                deploy.deploy()
            session.assert_not_called()

    def test_only_entry_points_uploaded_and_public_requests_have_no_credentials(self):
        private, public = Mock(), Mock()
        private.headers = {}; public.headers = {}
        uploaded = {}
        def upload(url, **kwargs):
            name, content, _ = kwargs['files']['file-1']
            uploaded[name] = content
            return Mock(json=lambda: {'status': 1})
        private.post.side_effect = upload
        private.get.side_effect = lambda url, **kw: Mock(json=lambda: {'status':1, 'data':{'content':uploaded[kw['params']['file']].decode()}})
        public.get.side_effect = lambda url, **kw: Mock(status_code=200 if url.endswith('app/') else 403 if any(x in url for x in ('config/', 'src/', 'bin/')) else 410, json=lambda: {'error':{'code':'service_moved'}})
        with patch.dict(os.environ, {'CPANEL_USER':'fixture','CPANEL_API_TOKEN':'private'}), patch.object(deploy.requests,'Session',side_effect=[private,public]):
            deploy.deploy()
        self.assertEqual(set(uploaded), {'.htaccess','index.php','share.php','purge-deleted-recipes.php'})
        self.assertNotIn('Authorization', public.headers)
        self.assertFalse(any('Mysql' in call.args[0] or 'Cron' in call.args[0] for call in private.post.call_args_list))

    def test_readback_mismatch_stops(self):
        session=Mock(); session.headers={}
        session.post.return_value.json.return_value={'status':1}
        session.get.return_value.json.return_value={'status':1,'data':{'content':'wrong'}}
        with patch.dict(os.environ,{'CPANEL_USER':'fixture','CPANEL_API_TOKEN':'private'}), patch.object(deploy.requests,'Session',return_value=session):
            with self.assertRaisesRegex(RuntimeError,'source mismatch'):
                deploy.deploy()

if __name__ == '__main__': unittest.main()
