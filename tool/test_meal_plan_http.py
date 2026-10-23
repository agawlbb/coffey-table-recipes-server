import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock

spec=importlib.util.spec_from_file_location('meal_http',Path(__file__).with_name('verify-meal-plan-http.py'))
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class MealHttpTests(unittest.TestCase):
    def response(self,status,code='authentication_required'):
        return Mock(status_code=status,json=Mock(return_value={'error':{'code':code}}))

    def test_waits_for_router_handover(self):
        session=Mock();session.get.side_effect=[self.response(404),self.response(401)]
        wait=Mock();module.verify(session,wait);wait.assert_called_once_with(5)

    def test_unauthenticated_success_fails_immediately(self):
        session=Mock();session.get.return_value=self.response(200)
        wait=Mock()
        with self.assertRaisesRegex(RuntimeError,'HTTP 200'):module.verify(session,wait)
        wait.assert_not_called()

    def test_retry_is_bounded(self):
        session=Mock();session.get.return_value=self.response(404)
        wait=Mock()
        with self.assertRaisesRegex(RuntimeError,'HTTP 404'):module.verify(session,wait)
        self.assertEqual(session.get.call_count,7)

    def test_wrong_authentication_error_fails(self):
        session=Mock();session.get.return_value=self.response(401,'other')
        with self.assertRaisesRegex(RuntimeError,'Unexpected authentication'):module.verify(session,Mock())
