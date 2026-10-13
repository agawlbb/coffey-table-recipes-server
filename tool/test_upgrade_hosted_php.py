import importlib.util
import json
import os
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('upgrade_php', Path(__file__).with_name('upgrade-hosted-php.py'))
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)
BEFORE = {'coffeybean.com': {'vhost':'coffeybean.com', 'version':'ea-php81',
    'documentroot':'/home/fixture/coffeybean.com', 'phpversion_source':{'domain':'coffeybean.com'}},
    'other.example': {'vhost':'other.example', 'version':'ea-php82'}}
AFTER = {**BEFORE, 'coffeybean.com': {**BEFORE['coffeybean.com'], 'version':'ea-php84'}}
PROBE = {'status':'ok', 'php_version':'8.4.20', 'sapi':'cli', 'extensions':True,
    'composer_bootstrap':True, 'database_read':True, 'import_preprocessing':True, 'recipe_read':True}
ROOT = '/home/fixture/coffeybean.com'
HANDLER = 'Options -Indexes\n# php handler\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php81 .php .php8 .phtml\n</IfModule>\n'


class PhpUpgradeTests(unittest.TestCase):
    def test_scope_rejects_changes_to_unrelated_sites(self):
        wrong = {**AFTER, 'other.example': {'vhost':'other.example', 'version':'ea-php84'}}
        with self.assertRaisesRegex(RuntimeError, 'unrelated'):
            upgrade.verify_scope(BEFORE, wrong, 'ea-php84')

    def test_invalid_runtime_or_missing_extensions_is_rejected(self):
        for result in ({**PROBE,'php_version':'8.1.34'}, {**PROBE,'extensions':False}, {'status':'error'}):
            with self.subTest(result=result), self.assertRaises(RuntimeError):
                upgrade.check_probe(result)

    def test_api_switch_targets_only_recipe_domain(self):
        session = Mock()
        response = Mock(status_code=200)
        response.json.return_value = {'status':1}
        session.get.return_value = response
        upgrade.set_version(session, 'ea-php84')
        self.assertEqual(session.get.call_args.kwargs['params'], {'version':'ea-php84','vhost':'coffeybean.com'})
        self.assertFalse(session.get.call_args.kwargs['allow_redirects'])

    def test_known_alias_error_requires_independent_requested_handler_readback(self):
        session = Mock()
        response = Mock(status_code=200)
        response.json.return_value = {'status':0,'errors':['No users correspond to the domain stale.example.']}
        session.get.return_value = response
        with patch.object(upgrade, 'versions', return_value=AFTER):
            upgrade.set_version(session, 'ea-php84')
        with patch.object(upgrade, 'versions', return_value=BEFORE), self.assertRaises(RuntimeError):
            upgrade.set_version(session, 'ea-php84')

    def test_unknown_api_errors_are_not_accepted_even_if_handler_matches(self):
        session = Mock()
        response = Mock(status_code=200)
        response.json.return_value = {'status':0,'errors':['Permission denied']}
        session.get.return_value = response
        with patch.object(upgrade, 'versions', return_value=AFTER) as query, self.assertRaises(RuntimeError):
            upgrade.set_version(session, 'ea-php84')
        query.assert_not_called()

    def exercise(self, mode='', inspect=False, available=('ea-php81','ea-php84')):
        files = {(ROOT, '.htaccess'):HANDLER}
        public = Mock(headers={})
        public.get.return_value = Mock(status_code=404)
        def upload(session, directory, name, content, overwrite=False):
            files[(directory, name)] = content.decode()
        def read(session, directory, name):
            return files.get((directory, name))
        versions = [BEFORE, AFTER]
        results = [PROBE, PROBE]
        if mode == 'web':
            versions = [BEFORE] + [AFTER] * 7 + [BEFORE]
            results = [PROBE] + [RuntimeError('private response')] * 6 + [PROBE]
        if mode == 'cli':
            results = [PROBE]
        with patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token'), \
                patch.object(upgrade.deploy, 'DIRECTORY', '/home/fixture/coffeybean.com/CoffeyTableRecipes'), \
                patch.object(upgrade.deploy.requests, 'Session', side_effect=[Mock(headers={}), public]), \
                patch.object(upgrade, 'versions', side_effect=versions), \
                patch.object(upgrade, 'installed', return_value=list(available)), \
                patch.object(upgrade, 'handler', return_value='cgi'), \
                patch.object(upgrade, 'upload', side_effect=upload), \
                patch.object(upgrade, 'read_file', side_effect=read), \
                patch.object(upgrade, 'public_checks', side_effect=results), \
                patch.object(upgrade, 'cli_probe', side_effect=RuntimeError('private response') if mode == 'cli' else None, return_value=PROBE), \
                patch.object(upgrade, 'set_version') as switch, \
                patch.object(upgrade, 'quarantine_probe') as cleanup, \
                patch.object(upgrade.time, 'sleep'):
            if mode or 'ea-php84' not in available and not inspect:
                with self.assertRaises(RuntimeError) as caught:
                    upgrade.upgrade(inspect)
                self.assertNotIn('private response', str(caught.exception))
            else:
                upgrade.upgrade(inspect)
            return switch, cleanup, files

    def test_inspection_never_changes_settings_or_files(self):
        switch, cleanup, files = self.exercise(inspect=True)
        switch.assert_not_called()
        cleanup.assert_not_called()
        self.assertEqual(files, {(ROOT, '.htaccess'):HANDLER})

    def test_unavailable_target_never_changes_settings(self):
        switch, cleanup, files = self.exercise(available=('ea-php81',))
        switch.assert_not_called()
        cleanup.assert_not_called()
        self.assertEqual(files, {(ROOT, '.htaccess'):HANDLER})

    def test_cli_failure_never_changes_handler_and_cleans_probe(self):
        switch, cleanup, _ = self.exercise(mode='cli')
        switch.assert_not_called()
        cleanup.assert_called_once()

    def test_web_failure_restores_original_handler_and_cleans_probe(self):
        switch, cleanup, files = self.exercise(mode='web')
        self.assertEqual([call.args[1] for call in switch.call_args_list], ['ea-php84','ea-php81'])
        cleanup.assert_called_once()
        self.assertEqual(files[(ROOT, '.htaccess')], HANDLER)

    def test_success_records_private_backup_and_verification(self):
        switch, cleanup, files = self.exercise()
        self.assertEqual(switch.call_args.args[1], 'ea-php84')
        cleanup.assert_called_once()
        receipts = [json.loads(content) for (_, name), content in files.items() if name.startswith('php-upgrade-after-')]
        self.assertEqual(receipts[0]['other_domains_unchanged'], True)
        self.assertEqual(files[(ROOT, '.htaccess')], HANDLER.replace('ea-php81','ea-php84'))

    def test_directory_update_changes_only_php_handler_preserving_other_rules(self):
        self.assertEqual(upgrade.php_handler_content(HANDLER, 'ea-php84'), HANDLER.replace('ea-php81','ea-php84'))
        for content in ('Options -Indexes\n', HANDLER + HANDLER):
            with self.assertRaisesRegex(RuntimeError, 'ambiguous'):
                upgrade.php_handler_content(content, 'ea-php84')

    def test_cli_failure_removes_only_exact_temporary_job(self):
        with patch.object(upgrade.migration, 'cron'), \
                patch.object(upgrade.migration, 'remove_job') as cleanup, \
                patch.object(upgrade, 'read_file', return_value=json.dumps({'status':'error'})):
            result = upgrade.cli_probe(Mock(), 'fixture', 'probe.php', 'fixture-token', 'receipt.json')
            self.assertEqual(result['status'], 'error')
            cleanup.assert_called_once()
            self.assertIn('probe.php fixture-token', cleanup.call_args.args[1])
