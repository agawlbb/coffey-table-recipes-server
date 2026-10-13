import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('hosted_php', Path(__file__).with_name('check-hosted-php.py'))
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


class HostedPhpTests(unittest.TestCase):
    def test_selects_only_recipe_domain(self):
        self.assertEqual(check.selected_version({'status':1,'data':[
            {'vhost':'unrelated.example','version':'ea-php74'},
            {'vhost':'coffeybean.com','version':'ea-php82'}]}), 'ea-php82')

    def test_unresolved_or_ambiguous_handler_is_rejected(self):
        for data in ([], [{'vhost':'coffeybean.com','version':'inherit'}],
                     [{'vhost':'coffeybean.com','version':'ea-php82'}] * 2):
            with self.subTest(data=data), self.assertRaises(RuntimeError):
                check.selected_version({'status':1,'data':data})

    def test_failed_query_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'details omitted'):
            check.selected_version({'status':0,'errors':['private details']})
