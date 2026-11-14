"""Capture durable storage and a transactional DB dump under a Linux write monitor."""
import ctypes
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile

SOURCE = '/home/novinpay/novinpay/storage/app'
PARENT = '/home/novinpay/novinpay-private-backups'
# Modify, attribute, close-write, rename, create, delete, self-move/delete, unmount.
MUTATIONS = 0x00002 | 0x00004 | 0x00008 | 0x00040 | 0x00080 | 0x00100 | 0x00200 | 0x00400 | 0x00800 | 0x02000
DIRECTORY_ONLY_NOFOLLOW = 0x01000000 | 0x02000000

class SnapshotStop(Exception):
    pass

def require(ok, check):
    if not ok:
        raise SnapshotStop(check)

def identities(root):
    result = {}
    pending = ['']
    while pending:
        relative = pending.pop()
        path = os.path.join(root, relative)
        item = os.lstat(path)
        is_dir = stat.S_ISDIR(item.st_mode)
        require(is_dir or (stat.S_ISREG(item.st_mode) and item.st_nlink == 1), 'storage_path_type')
        result[relative] = (is_dir, item.st_dev, item.st_ino, item.st_ctime_ns, item.st_mtime_ns, item.st_size, item.st_mode)
        if is_dir:
            pending.extend(os.path.join(relative, name) for name in os.listdir(path))
    return result

def assert_quiet(fd):
    try:
        data = os.read(fd, 65536)
    except BlockingIOError:
        return
    # Any event, including a kernel queue overflow or discarded watch, invalidates the snapshot.
    raise SnapshotStop('storage_mutated_during_snapshot' if data else 'storage_monitor_closed')

def require_local_mount(root):
    selected = None
    with open('/proc/self/mountinfo', encoding='ascii') as mounts:
        for line in mounts:
            mount, filesystem = line.rstrip().split(' - ', 1)
            point = mount.split()[4].replace(r'\040', ' ')
            if root == point or root.startswith(point.rstrip('/') + '/'):
                if selected is None or len(point) >= len(selected[0]):
                    selected = (point, filesystem.split()[0])
    require(selected is not None and selected[1] in ('ext4', 'xfs', 'btrfs'), 'storage_monitor_filesystem')

def take_snapshot(source, destination, dump):
    require(os.path.realpath(source) == source, 'storage_root_identity')
    require_local_mount(source)
    libc = ctypes.CDLL(None, use_errno=True)
    init = libc.inotify_init1
    init.argtypes = [ctypes.c_int]
    add = libc.inotify_add_watch
    add.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_uint32]
    fd = init(os.O_NONBLOCK | os.O_CLOEXEC)
    require(fd >= 0, 'storage_monitor_unavailable')
    try:
        initial = identities(source)
        root_device = initial[''][1]
        for relative, state in initial.items():
            require(state[1] == root_device, 'storage_mount_boundary')
            if state[0]:
                path = os.path.join(source, relative)
                require(add(fd, os.fsencode(path), MUTATIONS | DIRECTORY_ONLY_NOFOLLOW) >= 0, 'storage_watch_unavailable')
        require(identities(source) == initial, 'storage_changed_during_watch_setup')
        assert_quiet(fd)
        os.makedirs(destination, mode=0o700)
        for relative, state in sorted(initial.items()):
            if not relative:
                continue
            target = os.path.join(destination, relative)
            if state[0]:
                os.mkdir(target, mode=0o700)
                continue
            path = os.path.join(source, relative)
            with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as src, open(target, 'xb') as dst:
                current = os.fstat(src.fileno())
                require((current.st_dev, current.st_ino) == state[1:3], 'storage_file_replaced')
                shutil.copyfileobj(src, dst, length=131072)
                dst.flush()
                os.fsync(dst.fileno())
            # The staging area remains private. Preserve the backed-up file permissions.
            os.chmod(target, stat.S_IMODE(state[-1]))
        assert_quiet(fd)
        dump()
        assert_quiet(fd)
        require(identities(source) == initial, 'storage_identity_changed_during_snapshot')
        assert_quiet(fd)
        for relative, state in initial.items():
            if state[0]:
                os.chmod(os.path.join(destination, relative), stat.S_IMODE(state[-1]))
        return len([state for state in initial.values() if not state[0]])
    finally:
        os.close(fd)

def main():
    require(sys.platform.startswith('linux') and os.geteuid() == 1321 and len(sys.argv) == 2, 'snapshot_execution_identity')
    out = sys.argv[1]
    if out == '--probe':
        require_local_mount(SOURCE)
        os.umask(0o077)
        with tempfile.TemporaryDirectory(prefix='pr17-capture-probe-', dir=PARENT) as scratch:
            source = os.path.join(scratch, 'source')
            os.mkdir(source)
            require(os.stat(source).st_dev == os.stat(SOURCE).st_dev, 'probe_mount_mismatch')
            path = os.path.join(source, 'record')
            with open(path, 'xb') as probe:
                probe.write(b'probe-original')
            dest = os.path.join(scratch, 'quiet')
            require(take_snapshot(source, dest, lambda: None) == 1, 'quiet_probe')
            with open(os.path.join(dest, 'record'), 'rb') as copied:
                require(copied.read() == b'probe-original', 'probe_snapshot_bytes')
            def mutate():
                with open(path, 'wb') as probe:
                    probe.write(b'probe-replacement')
                with open(path, 'wb') as probe:
                    probe.write(b'probe-original')
            try:
                take_snapshot(source, os.path.join(scratch, 'mutated'), mutate)
            except SnapshotStop as error:
                require(str(error) == 'storage_mutated_during_snapshot', 'mutation_probe_unexpected')
            else:
                raise SnapshotStop('mutation_probe_not_detected')
        print(json.dumps(dict(result='HOSTED_CAPTURE_MONITOR_PASS')))
        return
    require(os.path.dirname(out) == PARENT and os.path.realpath(out) == out
            and os.path.basename(out).startswith('recovery-') and len(os.path.basename(out)) <= 80, 'snapshot_destination_scope')
    directory = os.stat(out)
    require(directory.st_uid == 1321 and stat.S_IMODE(directory.st_mode) == 0o700, 'snapshot_private_destination')
    option = os.path.join(out, 'client.cnf')
    credentials = os.lstat(option)
    require(stat.S_ISREG(credentials.st_mode) and credentials.st_uid == 1321
            and stat.S_IMODE(credentials.st_mode) == 0o600, 'snapshot_private_client_config')
    os.umask(0o077)
    def dump():
        with open(os.path.join(out, 'database.sql'), 'xb') as sql, open(os.path.join(out, 'capture-dump-error.txt'), 'xb') as errors:
            result = subprocess.run(['/bin/mariadb-dump', '--defaults-file=' + option, '--single-transaction',
                '--skip-lock-tables', '--quick', '--hex-blob', '--order-by-primary', '--skip-extended-insert',
                '--complete-insert', '--skip-comments', '--skip-add-locks', '--skip-disable-keys',
                '--default-character-set=utf8mb4', '--tz-utc', '--no-tablespaces', '--skip-add-drop-table',
                '--skip-add-drop-trigger', '--triggers', 'novinpay_live1'],
                stdout=sql, stderr=errors, timeout=120, check=False)
            require(result.returncode == 0 and 1000 < sql.tell() <= 67108864, 'transactional_dump_failed')
            sql.flush()
            os.fsync(sql.fileno())
    count = take_snapshot(SOURCE, os.path.join(out, 'snapshot/novinpay/storage/app'), dump)
    print(json.dumps(dict(result='CONSISTENT_CAPTURE_PASS', files=count)))

if __name__ == '__main__':
    try:
        main()
    except SnapshotStop as error:
        print(json.dumps(dict(result='CONSISTENT_CAPTURE_STOP', check=str(error))))
        sys.exit(1)
    except Exception:
        print(json.dumps(dict(result='CONSISTENT_CAPTURE_STOP', check='capture_runtime_failure')))
        sys.exit(1)
