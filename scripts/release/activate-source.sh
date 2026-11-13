#!/bin/bash
set +x
set -Eeuo pipefail
umask 077
app=/home/novinpay/novinpay
kit=/home/novinpay/novinpay-direct-pr13
manifest="${1-}"
pin="${2-}"
tools="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
phase=identity
passed=false
schema_changed=false
trap 'if [[ "$passed" != true ]]; then if [[ -e "$app/storage/framework/down" ]]; then down=true; else down=false; fi; printf "{\"result\":\"SOURCE_RELEASE_STOP\",\"phase\":\"%s\",\"maintenanceActive\":%s,\"schemaMayHaveChanged\":%s,\"retrySafe\":false}\n" "$phase" "$down" "$schema_changed"; fi' EXIT
p() { /opt/cpanel/ea-php84/root/usr/bin/php -d display_errors=0 -d log_errors=0 -d opcache.enable_cli=0 -d opcache.file_cache_only=0 -d opcache.file_cache= "$@"; }
contract=$(p "$tools/activate-source.php" "$manifest" "$pin" contract)
mapfile -t values <<<"$contract"
test "${#values[@]}" -eq 7
base="${values[0]}"
target="${values[1]}"
release="${values[2]}"
target_branch="${values[3]}"
asset="${values[4]}"
success_result="${values[5]}"
mode="${values[6]}"
journal="$kit/$release-attempt"
status() { curl -sS --connect-timeout 10 --max-time 25 -o /dev/null -w '%{http_code}' "https://novinpaypos.com$1"; }
verify_ippanel() {
    p -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $otp = app(App\Services\CustomerOtpSms::class);
        $login = app(App\Services\CustomerLogin::class);
        $testPhone = config("customer.test_sms_phone");
        if (!$app->environment("production") || !$app->configurationIsCached()
            || $app->getCachedConfigPath() !== getcwd()."/bootstrap/cache/config.php"
            || $otp->provider() !== "ippanel" || !$otp->enabled() || !$otp->configured()
            || config("customer.sms_enabled") !== false
            || config("customer.notifications_enabled") !== false
            || config("customer.test_reset_on_logout") !== true
            || !is_string($testPhone) || !$login->testUsesSms($testPhone) || $login->privateTestCode($testPhone) !== null) {
            fwrite(STDERR, "IPPANEL_CONFIGURATION_MISMATCH\n");
            exit(1);
        }
        echo "IPPANEL_CONFIGURATION_PRESERVED\n";
    '
}
cd "$kit"
test ! -L .operation.lock
exec 9>>.operation.lock
test "$(stat -c %a .operation.lock)" = 600
test "$(stat -c %u .operation.lock)" = 1321
flock -n 9
sha256sum -c --strict "$release.sha256"

test "$(id -u)" = 1321
test "$(readlink -f /home/novinpay/public_html)" = "$app/public"
test ! -e "$journal" && test ! -L "$journal"
cd "$app"
test "$(git rev-parse HEAD)" = "$base"
test "$(git remote get-url origin)" = https://github.com/khashayarxy/NovinPay.git
for file in storage/framework/down storage/framework/maintenance.php public/hot; do
    test ! -e "$file" && test ! -L "$file"
done
git diff --cached --quiet
git diff --quiet -- . ':(exclude)public/.htaccess'
for file in .env bootstrap/cache/config.php public/.htaccess storage/app/private/support-chat/installation.env public/build/manifest.json; do
    test -f "$file" && test ! -L "$file"
done
phase=source_contract
git fetch --no-tags origin "refs/heads/$target_branch"
test "$(git rev-parse FETCH_HEAD)" = "$target"
git merge-base --is-ancestor "$base" "$target"
(
    contract_dir=$(mktemp -d -- "$kit/.source-release-contract.XXXXXXXX")
    trap 'rm -f -- "$contract_dir/actual"; rmdir -- "$contract_dir"' EXIT
    git -c core.quotePath=true diff --no-renames --name-only "$base" "$target" >"$contract_dir/actual"
    diff -u -- "$kit/$release-source.txt" "$contract_dir/actual"
)
phase=ippanel_baseline
verify_ippanel
p "$tools/activate-source.php" "$manifest" "$pin" baseline-files
phase=recovery_runtime_and_quota
p "$tools/activate-source.php" "$manifest" "$pin" preflight
mkdir -m 0700 "$journal"
p "$tools/activate-source.php" "$manifest" "$pin" migration-paths >"$journal/migration-paths.txt"
mapfile -t migration_paths <"$journal/migration-paths.txt"
if [[ "$mode" == novinpay-application-release-v1 ]]; then
    test "${#migration_paths[@]}" -gt 0
    mkdir -m 0700 "$journal/migrations"
    git archive --format=tar --output="$journal/migrations.tar" "$target" -- "${migration_paths[@]}"
    tar -xf "$journal/migrations.tar" -C "$journal/migrations"
    test -z "$(find "$journal/migrations" -type l -print -quit)"
    test "$(find "$journal/migrations" -type f | wc -l)" -eq "${#migration_paths[@]}"
    git diff --diff-filter=A --name-only "$base" "$target" >"$journal/added-source-files.txt"
    install -m 0600 bootstrap/cache/config.php "$journal/config-before.php"
    install -m 0600 bootstrap/cache/routes-v7.php "$journal/routes-before.php"
    cmp -s bootstrap/cache/config.php "$journal/config-before.php"
    cmp -s bootstrap/cache/routes-v7.php "$journal/routes-before.php"
fi
p "$tools/activate-source.php" "$manifest" "$pin" quota
p "$tools/activate-source.php" "$manifest" "$pin" web-check
phase=rollback_capture
git -c core.quotePath=true diff --diff-filter=M --name-only "$base" "$target" >"$journal/baseline-source-files.txt"
mapfile -t changed <"$journal/baseline-source-files.txt"
git archive --format=tar --output="$journal/baseline-source.tar" "$base" -- "${changed[@]}"
tar -tf "$journal/baseline-source.tar" >"$journal/rollback-inventory.txt"
test -s "$journal/rollback-inventory.txt"
routes=bootstrap/cache/routes-v7.php
chat=public/build/support-chat/manifest.json
for path in "$routes" "$chat"; do
    test -f "$path" && test ! -L "$path"
done
p "$tools/activate-source.php" "$manifest" "$pin" baseline-files
build="$app/public/build"
test -d "$build" && test ! -L "$build" && test -d "$build/assets" && test ! -L "$build/assets"
install -m 0600 -- "$build/manifest.json" "$journal/vite-manifest-before.json"
sha256sum "$journal/baseline-source.tar" "$journal/vite-manifest-before.json" >"$journal/rollback.sha256"
sha256sum --check --strict "$journal/rollback.sha256"
protected=(.env public/.htaccess storage/app/private/support-chat/installation.env storage/framework/site-maintenance/state.json storage/framework/site-maintenance/audit.jsonl "$chat")
if [[ "$mode" != novinpay-application-release-v1 ]]; then protected+=(bootstrap/cache/config.php "$routes"); fi
sha256sum "${protected[@]}" >"$journal/protected.sha256"
phase=stage_vite_assets
stage="$journal/vite"

mkdir -m 0700 "$stage"
unzip -q "$kit/$release-vite.zip" -d "$stage"
test -z "$(find "$stage" -type l -print -quit)"
test "$(find "$stage" -type f | wc -l)" -eq 2
( cd "$stage"; sha256sum -c --strict "$kit/$release-assets.sha256" )
test ! -L "$build/$asset"
if [[ -e "$build/$asset" ]]; then cmp -s "$stage/$asset" "$build/$asset"; fi
chmod 0644 "$stage/$asset" "$stage/manifest.json"
phase=maintenance
p artisan down --render=errors::503 --retry=60 --no-interaction >"$journal/maintenance.log" 2>&1
test -f storage/framework/down && test -f storage/framework/maintenance.php
test "$(status /login)" = 503
sha256sum --check --strict "$journal/protected.sha256"
if [[ "$mode" == novinpay-application-release-v1 ]]; then
    phase=reviewed_migration
    schema_changed=true
    p "$tools/activate-source.php" "$manifest" "$pin" migrate-reviewed >"$journal/migration.log" 2>&1
    p "$tools/activate-source.php" "$manifest" "$pin" database-ready
fi
phase=source
(umask 022; git merge --ff-only "$target") >"$journal/source.log" 2>&1
test "$(git rev-parse HEAD)" = "$target"
git diff --cached --quiet
git diff --quiet -- . ':(exclude)public/.htaccess'
phase=runtime
if [[ "$mode" == novinpay-application-release-v1 ]]; then
    p artisan config:cache --no-interaction >"$journal/config.log" 2>&1
    p artisan route:cache --no-interaction >"$journal/routes.log" 2>&1
fi
p artisan view:cache --no-interaction >"$journal/views.log" 2>&1
p "$tools/activate-source.php" "$manifest" "$pin" ready
verify_ippanel
phase=vite_publication
cmp -s "$build/manifest.json" "$journal/vite-manifest-before.json"
if [[ ! -e "$build/$asset" ]]; then ln -- "$stage/$asset" "$build/$asset"; fi
mv -T -- "$stage/manifest.json" "$build/manifest.json"
( cd "$build"; sha256sum -c --strict "$kit/$release-assets.sha256" )
p "$tools/activate-source.php" "$manifest" "$pin" web-cache
sha256sum --check --strict "$journal/protected.sha256"
phase=reopen
p artisan up --no-interaction >"$journal/up.log" 2>&1
test ! -e storage/framework/down && test ! -e storage/framework/maintenance.php
phase=https_acceptance
p "$tools/activate-source.php" "$manifest" "$pin" smoke
sha256sum --check --strict "$journal/protected.sha256"
verify_ippanel
printf '{"result":"%s","commit":"%s","migrationsApplied":%s,"smsConfigurationPreserved":true,"activeProvider":"ippanel","otpReady":true,"smsirSendingEnabled":false,"testResetOnLogoutPreserved":true,"testSmsDeliveryPreserved":true,"sendingExecuted":false}\n' "$success_result" "$target" "${#migration_paths[@]}" >"$journal/complete.json"
cat "$journal/complete.json"
passed=true
