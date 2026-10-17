import importlib.util
import json
from pathlib import Path
from unittest.mock import Mock, patch
import unittest

spec = importlib.util.spec_from_file_location('rollout_deploy', Path(__file__).with_name('deploy-backend.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class SharedRolloutGateTests(unittest.TestCase):
    def state(self, phase='migrated'):
        return {'phase': phase, 'backup': {'restore_verified': True},
                'applied_checksums': {name: deploy.hashlib.sha256(b'fixture sql').hexdigest()
                    for name in ('011_shared_recipes.sql', '012_canonical_relationships.sql')}}

    def run_gate(self, state, marker_status=1):
        session = Mock()
        state_response = Mock()
        state_response.json.return_value = {'status': 1, 'data': {'content': json.dumps(state)}}
        marker = Mock()
        marker.json.return_value = {'status': marker_status}
        session.get.side_effect = [state_response, marker]
        with patch.object(Path, 'is_file', return_value=True), patch.object(Path, 'read_bytes', return_value=b'fixture sql'):
            deploy.require_shared_rollout_ready(session)
        session.post.assert_not_called()
        return session

    def test_verified_migration_requires_maintenance(self):
        self.assertEqual(self.run_gate(self.state()).get.call_count, 2)

    def test_missing_marker_stops_before_upload(self):
        with self.assertRaisesRegex(RuntimeError, 'Maintenance marker'):
            self.run_gate(self.state(), 0)

    def test_partial_state_stops_before_upload(self):
        with self.assertRaisesRegex(RuntimeError, 'migration/restore'):
            self.run_gate(self.state('backed_up'))

    def test_missing_restore_drill_stops(self):
        state = self.state(); state['backup']['restore_verified'] = False
        with self.assertRaisesRegex(RuntimeError, 'migration/restore'):
            self.run_gate(state)

    def test_migration_bytes_must_match(self):
        state = self.state(); state['applied_checksums']['012_canonical_relationships.sql'] = 'wrong'
        with self.assertRaisesRegex(RuntimeError, 'migration/restore'):
            self.run_gate(state)

    def test_completed_rollout_permits_future_matching_packages(self):
        self.assertEqual(self.run_gate(self.state('resumed')).get.call_count, 1)

    def test_no_state_cannot_be_treated_as_ready(self):
        with self.assertRaisesRegex(RuntimeError, 'migration/restore'):
            self.run_gate({})


if __name__ == '__main__':
    unittest.main()
