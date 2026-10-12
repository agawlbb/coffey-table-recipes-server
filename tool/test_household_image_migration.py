import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('household_migration', Path(__file__).with_name('migrate-household-images.py'))
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)


class MigrationJobTests(unittest.TestCase):
    def test_cleanup_retains_other_account_jobs(self):
        rows = [{'command': 'other-site-backup', 'count': 1}, {'command': 'reviewed-migration', 'count': 2}]
        with patch.object(migration, 'cron', side_effect=[rows, [], rows[:1]]) as cron:
            migration.remove_job(Mock(), 'reviewed-migration')
            self.assertEqual(cron.call_args_list[1].args[1], 'remove_line')
            self.assertEqual(cron.call_args_list[1].kwargs, {'line': 2})

    def test_cleanup_failure_is_reported(self):
        rows = [{'command': 'reviewed-migration', 'count': 2}]
        with patch.object(migration, 'cron', side_effect=[rows, [], rows]):
            with self.assertRaisesRegex(RuntimeError, 'cleanup failed'):
                migration.remove_job(Mock(), 'reviewed-migration')

    def test_scheduler_errors_are_redacted(self):
        session = Mock()
        session.post.return_value.json.return_value = {'cpanelresult': {
            'event': {'result': 0}, 'data': [{'reason': 'private-detail'}]}}
        with patch.dict(migration.os.environ, CPANEL_USER='fixture'):
            with self.assertRaisesRegex(RuntimeError, '^Migration scheduling failed; response details omitted$'):
                migration.cron(session, 'listcron')

    def test_migration_receipt_and_cleanup(self):
        session = Mock(headers={})
        session.post.return_value.json.return_value = {'status': 1}
        session.get.return_value.json.return_value = {'status': 1, 'data': {
            'content': '{"status":"ok","enabled":0}'}}
        with patch.dict(migration.os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-only'), \
                patch.object(migration.deploy.requests, 'Session', return_value=session), \
                patch.object(migration, 'cron') as cron, \
                patch.object(migration, 'remove_job') as cleanup:
            migration.migrate()
            self.assertEqual(session.post.call_count, 2)
            self.assertEqual(cron.call_args.args[1], 'add_line')
            cleanup.assert_called_once_with(session, cron.call_args.kwargs['command'])

    def test_failed_receipt_still_removes_job(self):
        session = Mock(headers={})
        session.post.return_value.json.return_value = {'status': 1}
        session.get.return_value.json.return_value = {'status': 1, 'data': {
            'content': '{"status":"error","type":"private-detail"}'}}
        with patch.dict(migration.os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-only'), \
                patch.object(migration.deploy.requests, 'Session', return_value=session), \
                patch.object(migration, 'cron'), \
                patch.object(migration, 'remove_job') as cleanup:
            with self.assertRaisesRegex(RuntimeError, '^Hosted migration failed; inspect protected receipt$'):
                migration.migrate()
            cleanup.assert_called_once()
