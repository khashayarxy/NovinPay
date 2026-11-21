#!/bin/bash
# Run from the private cPanel account after the qualified bundle is staged.
set -euo pipefail
umask 077
[[ $# == 3 && ($1 == deploy || $1 == status) ]]
action=$1
release=$2
list_pin=$3
[[ $release =~ ^[a-z0-9][a-z0-9-]+$ && $list_pin =~ ^[a-f0-9]{64}$ ]]
cd /home/novinpay/novinpay-direct-pr13
list="$release.sha256"
manifest="$release.json"
launcher="tools-$release/launch-detached.py"
printf '%s  %s\n' "$list_pin" "$list" | sha256sum --check --strict --status
sha256sum --check --strict --status "$list"
members=("$manifest" "$launcher" "$release-source.txt" "$release-vite.zip" "$release-assets.sha256")
for file in activate-source.sh activate-source.php source-deployment-state.php recovery-contract.php runtime.php lib.php schema-contract.php trigger-contracts.json backup-policy.php; do
    members+=("tools-$release/$file")
done
for member in "${members[@]}"; do
    awk -v name="$member" '$2 == name {found++} END {exit found != 1}' "$list"
done
python3 -I -c 'import json,sys
with open(sys.argv[1], encoding="utf-8") as stream:
    data = json.load(stream)
if data.get("releaseName") != sys.argv[2] or data.get("format") not in ("novinpay-source-release-v1", "novinpay-application-release-v1"):
    sys.exit("Unqualified release identity or format")' "$manifest" "$release"
pin=$(sha256sum "$manifest")
pin=${pin%% *}
mode=status
if [[ $action == deploy && ! -e dispatch-deploy-$pin && ! -e $release-attempt ]]; then
    mode=start
fi
exec python3 -I "$launcher" "$mode" deploy "$PWD/$manifest" "$pin"
