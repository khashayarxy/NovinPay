"""Build the offline payment country snapshot from a verified DB-IP Lite CSV.gz."""

import argparse
import csv
import gzip
import hashlib
import ipaddress
import json
import re
from pathlib import Path


def read_ranges(source, checksum):
    digest = hashlib.sha1()
    with gzip.open(source, 'rb') as raw:
        while chunk := raw.read(1024 * 1024):
            digest.update(chunk)
    if digest.hexdigest() != checksum:
        raise ValueError('Uncompressed CSV checksum differs from the published DB-IP SHA-1.')

    ranges = {4: [], 6: []}
    endpoints = {4: -1, 6: -1}
    with gzip.open(source, 'rt', encoding='ascii', newline='') as csv_file:
        for first, last, country in csv.reader(csv_file):
            start = ipaddress.ip_address(first)
            end = ipaddress.ip_address(last)
            version = start.version
            if version != end.version or start > end or int(start) <= endpoints[version]:
                raise ValueError('Source contains unordered or overlapping IP ranges.')
            if not re.fullmatch(r'[A-Z]{2}', country):
                raise ValueError('Source contains an unrecognized country code.')
            endpoints[version] = int(end)
            if country == 'ZZ':
                continue
            label = country == 'IR'
            previous = ranges[version][-1] if ranges[version] else None
            if previous and previous[2] == label and previous[1] + 1 == int(start):
                previous[1] = int(end)
            else:
                ranges[version].append([int(start), int(end), label])
    return ranges


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('month', help='Published release month, YYYY-MM')
    parser.add_argument('sha1', help='Published checksum of the uncompressed CSV')
    parser.add_argument('output', type=Path, help='Write a new snapshot for review')
    args = parser.parse_args()
    if not re.fullmatch(r'\d{4}-(0[1-9]|1[0-2])', args.month):
        parser.error('month must use YYYY-MM')
    ranges = read_ranges(args.source, args.sha1.lower())
    for rows in ranges.values():
        if {row[2] for row in rows} != {False, True}:
            raise ValueError('Expected domestic and foreign coverage for each address family.')
    sections = []
    for version, width in [(4, 8), (6, 32)]:
        rows = [json.dumps([f'{first:0{width}x}', f'{last:0{width}x}', label], separators=(',', ':'))
                for first, last, label in ranges[version]]
        sections.append(f'"v{version}":[\n' + ',\n'.join(rows) + '\n]')
    output = '{"month":' + json.dumps(args.month) + ',\n' + ',\n'.join(sections) + '\n}\n'
    if len(output.encode('ascii')) > 262144:
        raise ValueError('Snapshot exceeds the configured runtime size limit.')
    with args.output.open('x', encoding='ascii', newline='\n') as snapshot:
        snapshot.write(output)


if __name__ == '__main__':
    main()
