#!/bin/bash
set -eu
runner="$PWD/scripts/release/qualified-operation.sh"
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
cd "$fixture"
if ! python3 -V >/dev/null 2>&1; then
    printf '#!/bin/bash\nexec python "$@"\n' > python3
    chmod +x python3
    export PATH="$PWD:$PATH"
fi
sed 's|^cd /home/novinpay/novinpay-direct-pr13$|cd "$PWD"|' "$runner" > run
mkdir tools-example
printf '%s\n' '{"releaseName":"example","format":"novinpay-application-release-v1"}' > example.json
touch example-source.txt example-vite.zip example-assets.sha256
for file in activate-source.sh activate-source.php source-deployment-state.php recovery-contract.php runtime.php lib.php schema-contract.php trigger-contracts.json backup-policy.php; do
    touch "tools-example/$file"
done
printf 'import sys\nprint(sys.argv[1])\n' > tools-example/launch-detached.py
sha256sum --text example.json example-* tools-example/* > example.sha256
pin=$(sha256sum example.sha256)
pin=${pin%% *}
[[ $(bash run status example "$pin") == status ]]
[[ $(bash run deploy example "$pin") == start ]]
mkdir example-attempt
[[ $(bash run deploy example "$pin") == status ]]
if bash run deploy example "$(printf '%064d' 0)"; then exit 1; fi
echo changed >> tools-example/activate-source.php
if bash run deploy example "$pin"; then exit 1; fi
sed '/tools-example\/activate-source.php$/d' example.sha256 > incomplete
mv incomplete example.sha256
pin=$(sha256sum example.sha256)
if bash run deploy example "${pin%% *}"; then exit 1; fi
echo QUALIFIED_DISPATCH_TEST_PASS
