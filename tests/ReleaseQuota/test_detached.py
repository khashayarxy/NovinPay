import importlib.util
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest


TOOL = Path(__file__).resolve().parents[2] / "scripts" / "release" / "launch-detached.py"
SPEC = importlib.util.spec_from_file_location("detached", TOOL)
DETACHED = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DETACHED)


@unittest.skipUnless(os.name == "posix", "Requires POSIX session and signal semantics")
class DetachedOperationTest(unittest.TestCase):
    def test_runner_survives_loss_of_launch_session_and_cannot_be_claimed_twice(self):
        with tempfile.TemporaryDirectory(prefix="novinpay-detach-") as temp:
            run = Path(temp) / "run"
            worker = (
                "import sys,time; from pathlib import Path;"
                "time.sleep(0.4); Path(sys.argv[1]).write_text('complete');"
            )
            parent = (
                "import runpy,sys,time; from pathlib import Path;"
                "tool=runpy.run_path(sys.argv[1]);"
                "tool['start'](Path(sys.argv[2]),[sys.executable,'-c',sys.argv[3],sys.argv[4]]);"
                "print('armed',flush=True); time.sleep(10)"
            )
            started = subprocess.Popen(
                [sys.executable, "-c", parent, str(TOOL), str(run), worker, str(run / "sentinel")],
                stdout=subprocess.PIPE, text=True, start_new_session=True,
            )
            with started.stdout:
                self.assertEqual(started.stdout.readline().strip(), "armed")
            os.killpg(started.pid, signal.SIGHUP)
            started.wait(timeout=5)
            with self.assertRaises(FileExistsError):
                DETACHED.start(run, [sys.executable, "-c", "raise SystemExit(9)"])
            for _ in range(100):
                if (run / "sentinel").is_file():
                    break
                time.sleep(0.05)
            self.assertEqual((run / "sentinel").read_text(), "complete")

    def test_worker_preserves_first_run_and_records_runner_failure(self):
        with tempfile.TemporaryDirectory(prefix="novinpay-detach-") as temp:
            run = Path(temp)
            self.assertEqual(DETACHED.supervise(run, [sys.executable, "-c", "raise SystemExit(75)"]), 75)
            self.assertEqual(json.loads((run / "complete.json").read_text())["exitCode"], 75)
            with self.assertRaises(FileExistsError):
                DETACHED.supervise(run, [sys.executable, "-c", "raise SystemExit(0)"])
            self.assertEqual(json.loads((run / "complete.json").read_text())["exitCode"], 75)


if __name__ == "__main__":
    unittest.main()
