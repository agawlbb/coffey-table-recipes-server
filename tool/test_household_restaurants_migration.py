import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('receipt_migration', Path(__file__).with_name('migrate-household-restaurants.py'))
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)


class ReceiptMigrationTests(unittest.TestCase):
    def test_only_dedicated_migration_is_uploaded_and_receipt_verified(self):
        session = Mock(headers={})
        session.post.return_value.json.return_value = {'status': 1}
        session.get.return_value.json.return_value = {'status': 1, 'data': {
            'content': '{"status":"ok","migration":"018_household_restaurants.sql","recipes":4,"service_reads_verified":true}'}}
        with patch.dict(migration.os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture'), \
                patch.object(migration.deploy.requests, 'Session', return_value=session), \
                patch.object(migration, 'cron') as cron, patch.object(migration, 'remove_job') as cleanup:
            migration.migrate()
            filenames = [call.kwargs['files']['file-1'][0] for call in session.post.call_args_list]
            self.assertEqual(filenames, ['enable-household-restaurants.php', '018_household_restaurants.sql'])
            self.assertIn('/bin/enable-household-restaurants.php', cron.call_args.kwargs['command'])
            cleanup.assert_called_once()

    def test_failed_migration_removes_only_its_job_and_redacts_errors(self):
        session = Mock(headers={})
        session.post.return_value.json.return_value = {'status': 1}
        session.get.return_value.json.return_value = {'status': 1, 'data': {
            'content': '{"status":"error","stage":"migration","type":"private detail"}'}}
        with patch.dict(migration.os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture'), \
                patch.object(migration.deploy.requests, 'Session', return_value=session), \
                patch.object(migration, 'cron'), patch.object(migration, 'remove_job') as cleanup:
            with self.assertRaisesRegex(RuntimeError, '^Hosted migration failed at migration; inspect protected receipt$'):
                migration.migrate()
            cleanup.assert_called_once()

    def test_permissions_restore_on_failure(self):
        session=Mock()
        session.post.return_value.json.return_value={'status':1}
        original=['SELECT','INSERT','UPDATE','DELETE']
        with patch.object(migration,'mysql',side_effect=[original,None,None,original]) as mysql, \
                patch.object(migration,'run_once',side_effect=RuntimeError('failed')):
            with self.assertRaisesRegex(RuntimeError,'failed'):
                migration.temporary_schema_migration(session,'fixture','fixture_recipes')
            self.assertEqual(mysql.call_args_list[-2].kwargs['privileges'],','.join(original))
