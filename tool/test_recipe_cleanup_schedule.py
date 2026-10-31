import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('cleanup',Path(__file__).with_name('schedule-recipe-cleanup.py'))
schedule=importlib.util.module_from_spec(spec)
spec.loader.exec_module(schedule)

class RetiredScheduleTests(unittest.TestCase):
    def test_obsolete_command_cannot_recreate_the_shared_host_job(self):
        with self.assertRaisesRegex(RuntimeError, 'Cleanup moved to the VPS'):
            schedule.schedule()

if __name__=='__main__': unittest.main()
