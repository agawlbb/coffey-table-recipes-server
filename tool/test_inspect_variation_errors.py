import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('diagnostics', Path(__file__).with_name('inspect-variation-errors.py'))
diagnostics = importlib.util.module_from_spec(spec)
spec.loader.exec_module(diagnostics)


class VariationDiagnosticsTests(unittest.TestCase):
    def test_private_events_emit_only_diagnostic_fields(self):
        rows = diagnostics.summarize('{"at":"2026-10-27T18:23:00+00:00","request":"aaaaaaaaaaaaaaaa","event":"recovered","code":null,"elapsed_ms":1,"prompt":"private"}\n{"at":"private"}')
        self.assertEqual(rows, [('2026-10-27T18:23:00+00:00', 'recovered', 'aaaaaaaaaaaaaaaa', '', '1')])
        self.assertNotIn('private', str(rows))

    def test_only_allowlisted_fields_leave_logs(self):
        text = '''[27-Oct-2026 18:22:00 UTC] Recipe AI failure: HTTP 0, transport 28, code unknown private secret
[27-Oct-2026 18:23:00 UTC] PHP Fatal error: Maximum execution time of 30 seconds exceeded /private/file.php
192.0.2.1 - - [27/Oct/2026:18:22:00 +0000] "POST /CoffeyTableRecipes/api/v1/households/1/recipes/2/variation HTTP/1.1" 504 99 "private request"
[26-Oct-2026 18:22:00 UTC] Recipe AI failure: HTTP 401, transport 0, code invalid_api_key
'''
        rows = diagnostics.summarize(text)
        self.assertEqual(len(rows), 3)
        self.assertEqual(rows[0][1:], ('provider', '0', '28', 'unknown'))
        self.assertEqual(rows[2][1:], ('variation_http', '504'))
        self.assertNotIn('private', str(rows))
        self.assertNotIn('192.0.2.1', str(rows))


if __name__ == '__main__':
    unittest.main()
