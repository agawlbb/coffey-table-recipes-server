import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('cleanup_schedule', Path(__file__).with_name('schedule-recipe-cleanup.py'))
schedule = importlib.util.module_from_spec(spec)
spec.loader.exec_module(schedule)


class CleanupScheduleTests(unittest.TestCase):
    def job(self):
        return {'command': '/opt/cpanel/ea-php82/root/usr/bin/php ' + schedule.migration.deploy.DIRECTORY + '/bin/purge-deleted-recipes.php',
                'minute': '15', 'hour': '3', 'day': '*', 'month': '*', 'weekday': '*'}

    @patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token')
    def test_existing_daily_job_is_not_duplicated(self):
        with patch.object(schedule.migration, 'cron', side_effect=[[self.job()], [self.job()]]) as cron:
            schedule.schedule()
            self.assertEqual([call.args[1] for call in cron.call_args_list], ['listcron', 'listcron'])

    @patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token')
    def test_missing_job_is_added_and_verified(self):
        unrelated = {'command': 'other-site-backup'}
        with patch.object(schedule.migration, 'cron', side_effect=[[unrelated], [], [unrelated, self.job()]]) as cron:
            schedule.schedule()
            self.assertEqual(cron.call_args_list[1].args[1], 'add_line')
            self.assertEqual(cron.call_args_list[1].kwargs['hour'], '3')

    @patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token')
    def test_wrong_schedule_repairs_only_the_recipe_command(self):
        wrong = {**self.job(), 'hour': '*'}
        with patch.object(schedule.migration, 'cron', side_effect=[[wrong], [], [self.job()]]), \
                patch.object(schedule.migration, 'remove_job') as cleanup:
            schedule.schedule()
            self.assertEqual(cleanup.call_args.args[1], wrong['command'])

    @patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token')
    def test_unverified_schedule_fails(self):
        with patch.object(schedule.migration, 'cron', side_effect=[[], [], []]):
            with self.assertRaisesRegex(RuntimeError, 'verification failed'):
                schedule.schedule()

    @patch.dict(os.environ, CPANEL_USER='../wrong', CPANEL_API_TOKEN='fixture-token')
    def test_invalid_account_does_not_contact_host(self):
        with patch.object(schedule.migration.deploy.requests, 'Session') as session:
            with self.assertRaisesRegex(RuntimeError, 'Configure hosting credentials'):
                schedule.schedule()
            session.assert_not_called()
