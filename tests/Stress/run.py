"""Measure Laravel HTTP kernel requests in disposable PHP workers, without a web server."""
import base64
import json
import math
import os
from pathlib import Path
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(__file__).resolve().parents[2]
WORKER = ROOT / "tests/Stress/request.php"


def main():
    with tempfile.TemporaryDirectory(prefix="novinpay-phase12-") as tmp:
        root = Path(tmp).resolve()
        for folder in ["framework/views", "framework/sessions", "framework/cache/data", "logs", "app/private"]:
            (root / folder).mkdir(parents=True)
        db = root / "stress.sqlite"
        db.touch()
        env = dict(os.environ, APP_ENV="testing", APP_DEBUG="false",
                   APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode(),
                   APP_URL="https://novinpaypos.test", DB_CONNECTION="sqlite",
                   DB_DATABASE=db.as_posix(), DB_URL="sqlite:///" + db.as_posix(),
                   LARAVEL_STORAGE_PATH=str(root), APP_CONFIG_CACHE=str(root / "config.php"),
                   APP_ROUTES_CACHE=str(root / "routes.php"), APP_EVENTS_CACHE=str(root / "events.php"),
                   SESSION_DRIVER="file", CACHE_STORE="file", MAIL_MAILER="array", QUEUE_CONNECTION="sync")

        def worker(action, index=0):
            run = subprocess.run(["php", str(WORKER), str(root), action, str(index)],
                                 cwd=ROOT, env=env, capture_output=True, text=True, timeout=100)
            if run.returncode:
                raise RuntimeError((run.stdout + run.stderr)[-1600:])
            if not run.stdout.lstrip().startswith(("{", "true")):
                raise RuntimeError(run.stdout[:500])
            return json.loads(run.stdout)

        worker("setup")
        failures = []
        for scenario, count in [("checkout", 16), ("reads", 25), ("poll", 16), ("slow", 3)]:
            with ThreadPoolExecutor(max_workers=count) as threads:
                futures = [threads.submit(worker, scenario, i) for i in range(count)]
                deadline = time.monotonic() + 60
                while len(list(root.glob(scenario + "-*.ready"))) < count:
                    for future in futures:
                        if future.done():
                            future.result()
                    if time.monotonic() > deadline:
                        raise TimeoutError(scenario + " barrier")
                    time.sleep(.025)
                (root / (scenario + ".go")).touch()
                samples = [future.result() for future in futures]
            durations = sorted(row["ms"] for row in samples)
            errors = [row for row in samples if not row["passed"]]
            metrics = dict(scenario=scenario, concurrency=count, requests=count,
                           error_percent=100 * len(errors) / count,
                           p50_ms=durations[math.ceil(count * .50) - 1],
                           p95_ms=durations[math.ceil(count * .95) - 1], max_ms=durations[-1],
                           max_queries=max(row["queries"] for row in samples))
            print(json.dumps(metrics), flush=True)
            if errors or (scenario == "slow" and metrics["p95_ms"] >= 800):
                failures.append((scenario, errors))
        worker("integrity")
        if failures:
            raise RuntimeError(str(failures))


if __name__ == "__main__":
    main()
