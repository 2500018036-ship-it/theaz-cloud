"""Exercise the real backup EXIT handler without touching Docker or live data."""

import os
from pathlib import Path
import re
import subprocess
import unittest


class BackupRecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        source = (Path(__file__).resolve().parents[2] / "scripts/ops/backup.sh").read_text()
        match = re.search(r"^resume\(\) \{\n.*?^\}\n(?=trap resume EXIT)", source, re.M | re.S)
        if not match:
            raise AssertionError("Backup recovery handler was not found")
        cls.handler = match.group()

    def recover(self, *, running="app web mail_worker", backup=0, docker=0, http=0):
        # Shell functions intercept all external actions. The actual EXIT trap
        # still runs with errexit/nounset, including the original failure code.
        script = """set -Eeuo pipefail
running=$TEST_RUNNING_SERVICES
compose=(docker compose)
docker() { printf 'DOCKER %s\\n' "$*"; return "$TEST_DOCKER_STATUS"; }
curl() { printf 'HTTP %s\\n' "$*" >&2; return "$TEST_HTTP_STATUS"; }
""" + self.handler + "\ntrap resume EXIT\nexit \"$TEST_BACKUP_STATUS\"\n"
        env = dict(os.environ, TEST_RUNNING_SERVICES=running,
                   TEST_BACKUP_STATUS=str(backup), TEST_DOCKER_STATUS=str(docker),
                   TEST_HTTP_STATUS=str(http))
        return subprocess.run([os.environ.get("BASH_TEST_BINARY", "bash"), "-c", script],
                              env=env, text=True, capture_output=True, timeout=10)

    def test_success_waits_for_all_previously_running_services_and_http(self):
        result = self.recover()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("start --wait --wait-timeout 180 app web mail_worker", result.stdout)
        self.assertIn("http://127.0.0.1:18080/ready", result.stderr)

    def test_backup_failure_still_restores_services_and_preserves_failure(self):
        result = self.recover(backup=23)
        self.assertEqual(result.returncode, 23)
        self.assertIn("start --wait", result.stdout)
        self.assertIn("HTTP ", result.stderr)

    def test_startup_failure_turns_successful_backup_into_failure(self):
        result = self.recover(docker=42)
        self.assertEqual(result.returncode, 1)
        self.assertIn("HTTP ", result.stderr)
        self.assertIn("recovery failed", result.stderr)

    def test_http_failure_turns_successful_backup_into_failure(self):
        result = self.recover(http=22)
        self.assertEqual(result.returncode, 1)
        self.assertIn("recovery failed", result.stderr)

    def test_recovery_failure_does_not_mask_original_backup_failure(self):
        self.assertEqual(self.recover(backup=23, docker=42, http=22).returncode, 23)

    def test_intentionally_stopped_web_and_worker_remain_stopped(self):
        result = self.recover(running="app db")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("start --wait --wait-timeout 180 app\n", result.stdout)
        self.assertNotIn("HTTP ", result.stderr)

    def test_no_running_application_services_does_not_start_anything(self):
        result = self.recover(running="db")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("DOCKER ", result.stdout)
        self.assertNotIn("HTTP ", result.stderr)


if __name__ == "__main__":
    unittest.main()
