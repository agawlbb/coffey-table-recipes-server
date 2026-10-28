"""The branch retires shopping HTTP routes without private cutover settings."""
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class RetiredShoppingTests(unittest.TestCase):
    def test_shopping_routes_are_retired_and_health_remains_available(self):
        root = Path(__file__).resolve().parents[1]
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        with tempfile.TemporaryFile() as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', 'index.php'], cwd=root, stdout=log, stderr=log)
            base = f'http://127.0.0.1:{port}/CoffeyTableRecipes/api/v1'
            try:
                for _ in range(100):
                    try:
                        with urlopen(base + '/health', timeout=2) as response:
                            self.assertEqual(response.status, 200)
                        break
                    except URLError:
                        time.sleep(.02)
                else:
                    self.fail('PHP fixture did not start')
                for path, methods in [('/households/1/shopping-list', ['GET', 'POST']),
                                      ('/households/1/shopping-list/sync-request', ['POST']),
                                      ('/home-assistant/shopping-list', ['GET', 'POST'])]:
                    for method in methods:
                        with self.subTest(path=path, method=method), self.assertRaises(HTTPError) as failure:
                            urlopen(Request(base + path, method=method, data=b'{}' if method == 'POST' else None), timeout=2)
                        self.assertEqual(failure.exception.code, 410)
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
