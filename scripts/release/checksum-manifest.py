"""Write or verify a GNU SHA-256 manifest for an explicit release inventory."""

import argparse
import hashlib
from pathlib import Path


def release_file(root, name):
    if (
        any(ord(char) < 32 or char in "\\:" for char in name)
        or any(part in {"", ".", ".."} for part in name.split("/"))
    ):
        raise ValueError(f"Invalid release path: {name!r}")

    path = root / name
    if not path.resolve().is_relative_to(root):
        raise ValueError(f"Release path escapes package: {name!r}")
    return path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("root", type=Path, help="Directory used by sha256sum --check")
    parser.add_argument("manifest", help="Manifest path relative to root")
    parser.add_argument("members", nargs="+", help="Ordered file paths relative to root")
    args = parser.parse_args()

    try:
        root = args.root.resolve(strict=True)
        manifest = release_file(root, args.manifest)
        seen = {manifest.resolve()}
        records = []
        for name in args.members:
            path = release_file(root, name)
            resolved = path.resolve(strict=True)
            if resolved in seen or not path.is_file():
                raise ValueError(f"Duplicate or invalid release member: {name!r}")
            seen.add(resolved)
            with path.open("rb") as payload:
                digest = hashlib.file_digest(payload, "sha256").hexdigest()
            records.append(f"{digest}  {name}\n")

        expected = "".join(records).encode("utf-8")
        if not args.check:
            with manifest.open("xb") as output:
                output.write(expected)
        if manifest.read_bytes() != expected:
            raise ValueError("Manifest does not match LF format and the exact release inventory")
    except (OSError, ValueError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
