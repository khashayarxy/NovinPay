"""Start exactly one reviewed hosting operation without a terminal connection."""

import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from datetime import datetime, timezone


KIT = Path("/home/novinpay/novinpay-direct-pr13")
PHP = "/opt/cpanel/ea-php84/root/usr/bin/php"


def receipt(directory, name, data):
    path = directory / name
    temp = directory / (name + ".new")
    payload = json.dumps({**data, "atUtc": datetime.now(timezone.utc).isoformat()})
    with temp.open("x", encoding="utf-8") as stream:
        stream.write(payload + "\n")
        stream.flush()
        os.fsync(stream.fileno())
    os.link(temp, path)
    temp.unlink()
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def start(directory, command):
    # The filesystem claim is durable and survives both process and terminal loss.
    directory.mkdir(mode=0o700)
    receipt(directory, "launch.json", {"result": "launch_claimed"})
    with (directory / "operation.log").open("xb") as log:
        try:
            process = subprocess.Popen(
                command,
                stdin=subprocess.DEVNULL,
                stdout=log,
                stderr=subprocess.STDOUT,
                close_fds=True,
                start_new_session=True,
                cwd=directory,
            )
        except OSError:
            receipt(directory, "complete.json", {"result": "spawn_failed", "exitCode": None})
            raise
    return process.pid


def supervise(directory, command):
    receipt(directory, "running.json", {"result": "running", "pid": os.getpid()})
    try:
        result = subprocess.run(command, stdin=subprocess.DEVNULL, check=False).returncode
    except OSError:
        receipt(directory, "complete.json", {"result": "exec_failed", "exitCode": None})
        return 1
    log = directory / "operation.log"
    log_synced = False
    try:
        if log.is_file() and not log.is_symlink():
            with log.open("rb") as stream:
                os.fsync(stream.fileno())
            log_synced = True
    except OSError:
        pass
    receipt(directory, "complete.json", {"result": "runner_exited", "exitCode": result, "logSynced": log_synced})
    return result


def verify(tool, manifest, pin, action):
    if os.geteuid() != 1321 or action not in {"capture", "resume", "deploy"}:
        raise ValueError
    if not re.fullmatch(r"[0-9a-f]{64}", pin) or manifest.parent != KIT:
        raise ValueError
    info = manifest.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 1321 or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 262144:
        raise ValueError
    raw = manifest.read_bytes()
    if hashlib.sha256(raw).hexdigest() != pin or tool.parent.parent != KIT or tool.parent.is_symlink():
        raise ValueError
    data = json.loads(raw)
    if data.get("stateDirectory") != str(KIT) or data.get("quotaProtocol") != 2:
        raise ValueError
    if action == "deploy":
        if data.get("format") not in {"novinpay-source-release-v1", "novinpay-application-release-v1", "novinpay-runtime-release-v1"}:
            raise ValueError
        return ["/bin/bash", str(tool.with_name("activate-source.sh")), str(manifest), pin]
    if data.get("format") != "novinpay-recovery-v1":
        raise ValueError
    return [PHP, "-d", "display_errors=0", "-d", "log_errors=0", "-d", "opcache.enable_cli=0",
            str(tool.with_name("recover.php")), str(manifest), pin, action]


def main():
    os.umask(0o077)
    mode, action, name, digest = sys.argv[1:]
    tool = Path(__file__).absolute()
    manifest = Path(name)
    command = verify(tool, manifest, digest, action)
    directory = KIT / f"dispatch-{action}-{digest}"
    if mode == "status" and action == "deploy":
        return subprocess.run([PHP, "-d", "opcache.enable_cli=0", str(tool.with_name("activate-source.php")),
                               str(manifest), digest, "status"], stdin=subprocess.DEVNULL, check=False).returncode
    if mode == "start":
        pid = start(directory, [sys.executable, "-I", str(tool), "worker", action, name, digest])
        print(json.dumps({"result": "DETACHED_OPERATION_STARTED", "manifestSha256": digest, "action": action, "pid": pid}))
        return 0
    if mode == "worker" and directory.is_dir() and not directory.is_symlink() and (directory / "launch.json").is_file():
        return supervise(directory, command)
    raise ValueError


if __name__ == "__main__":
    try:
        sys.exit(main())
    except FileExistsError:
        print("DETACHED_OPERATION_ALREADY_CLAIMED")
        sys.exit(1)
    except (OSError, ValueError, TypeError):
        print("DETACHED_OPERATION_STOP_PRESERVE_JOURNAL")
        sys.exit(1)
