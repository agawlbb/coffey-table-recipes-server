import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('breakfast_groups', Path(__file__).with_name('group-breakfast-ring.py'))
job = importlib.util.module_from_spec(spec)
spec.loader.exec_module(job)


class BreakfastGroupingJobTests(unittest.TestCase):
    def test_missing_credentials_never_schedule(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(job.jobs, 'cron') as cron:
            with self.assertRaises(RuntimeError):
                job.generate()
            cron.assert_not_called()

    def test_receipt_validation_and_cron_cleanup(self):
        for title in ('Breakfast Ring', 'Wrong recipe'):
            with self.subTest(title=title), tempfile.TemporaryDirectory() as directory:
                receipt = {'status': 'ok', 'title': title, 'recipe_id': 'fixture',
                           'groups': {'Dough': ['flour'], 'Sauce': ['sugar']}, 'ingredient_count': 2}
                response = Mock(status_code=200)
                response.json.return_value = {'status': 1, 'data': {'content': json.dumps(receipt)}}
                session = Mock(headers={})
                session.get.return_value = response
                original = Path.cwd()
                try:
                    os.chdir(directory)
                    Path('storage').mkdir()
                    with patch.dict(os.environ, CPANEL_USER='fixture', CPANEL_API_TOKEN='fixture-token'), \
                            patch.object(job.deploy.requests, 'Session', return_value=session), \
                            patch.object(job.jobs, 'cron') as cron, \
                            patch.object(job.jobs, 'remove_job') as remove:
                        if title == 'Breakfast Ring':
                            job.generate()
                            self.assertTrue(Path('storage/breakfast-groups-report.json').is_file())
                        else:
                            with self.assertRaises(RuntimeError):
                                job.generate()
                        remove.assert_called_once_with(session, cron.call_args.kwargs['command'])
                finally:
                    os.chdir(original)
