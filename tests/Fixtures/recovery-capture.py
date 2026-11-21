"""Synthetic Linux inotify regression. Never connects to a database."""
import importlib.util
from pathlib import Path
import tempfile

module_path = Path(__file__).resolve().parents[2] / 'scripts/release/capture.py'
spec = importlib.util.spec_from_file_location('capture', module_path)
capture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capture)

with tempfile.TemporaryDirectory(prefix='np-capture-test-') as root:
    base = Path(root)
    source = base / 'source'
    source.mkdir()
    record = source / 'record'
    record.write_bytes(b'original')
    assert capture.take_snapshot(str(source), str(base / 'quiet'), lambda: None) == 1
    assert (base / 'quiet/record').read_bytes() == b'original'

    def mutate():
        record.write_bytes(b'changed')
        record.write_bytes(b'original')

    try:
        capture.take_snapshot(str(source), str(base / 'partial'), mutate)
    except capture.SnapshotStop as error:
        assert str(error) == 'storage_mutated_during_snapshot'
    else:
        raise AssertionError('Reverted concurrent writes escaped detection')
    assert record.read_bytes() == b'original'

print('CAPTURE_MONITOR_REGRESSION_PASS')
