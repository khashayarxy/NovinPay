#!/bin/bash

# Supervise the child: never exec it and lose the final-result watchdog.
pr13_supervise() {
 local php="$1" entry="$2" manifest="$3" pin="$4" child_exit=0 report_exit=0
 if [ -e launch.started.json ] || [ -L launch.started.json ] ||
    [ -e launch.stdout ] || [ -L launch.stdout ] ||
    [ -e launch.stderr ] || [ -L launch.stderr ] ||
    [ -e launch.result.json ] || [ -L launch.result.json ]; then
  printf '%s\n' '{"result":"PR13_ACTIVATION_LAUNCH_STOP","check":"previous_launch_preserve","retrySafe":false,"maintenanceMayBeActive":true}'
  return 1
 fi
 set -C
 if ! printf '{"result":"PR13_ACTIVATION_LAUNCH_STARTED","manifest":"%s","retrySafe":false}\n' "$pin" >launch.started.json; then
  printf '%s\n' '{"result":"PR13_ACTIVATION_LAUNCH_STOP","check":"launch_marker_failed","retrySafe":false,"maintenanceMayBeActive":true}'
  return 1
 fi
 printf '%s\n' '{"result":"PR13_ACTIVATION_LAUNCH_STARTED","retrySafe":false}'
 "$php" -d opcache.enable_cli=0 -d opcache.file_cache_only=0 -d opcache.file_cache= "$entry" "$manifest" "$pin" >launch.stdout 2>launch.stderr || child_exit=$?
 # -n is only for the JSON reporter, never application/migration execution.
 "$php" -n -d display_errors=0 -d display_startup_errors=0 "$(dirname "${BASH_SOURCE[0]}")/pr13-launch-result.php" \
  "$PWD/launch.stdout" "$child_exit" "$manifest" "$pin" >launch.result.json 2>>launch.stderr || report_exit=$?
 if [ -s launch.result.json ] && { [ "$report_exit" -eq 0 ] || [ "$report_exit" -eq 1 ] || [ "$report_exit" -eq 75 ]; }; then
  cat launch.result.json || return 1
  return "$report_exit"
 fi
 printf '{"result":"PR13_ACTIVATION_LAUNCH_STOP","phase":"launcher","check":"reporter_missing_or_failed","childExitCode":%s,"retrySafe":false,"maintenanceMayBeActive":true}\n' "$child_exit"
 return 1
}

# Sourcing exposes only the supervisor for isolated synthetic regression tests.
if [ "${BASH_SOURCE[0]}" != "$0" ]; then return; fi
set -eu
umask 077
reported=0
on_exit() {
 if [ "$reported" -eq 0 ]; then
  printf '%s\n' '{"result":"PR13_ACTIVATION_LAUNCH_STOP","phase":"launcher","check":"launcher_interrupted_or_guard_failed","retrySafe":false,"maintenanceMayBeActive":true}'
 fi
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
directory='/home/novinpay/novinpay-release-pipeline-v1/pr13-activation-72h-v4'
test "$#" -eq 2
[[ "$1" =~ ^[a-f0-9]{64}$ ]] && [[ "$2" =~ ^[a-f0-9]{64}$ ]]
test "$(id -u)" -eq 1321
test ! -L "$directory"
test "$(stat -c '%a' "$directory")" = 700
cd "$directory"
printf '%s  %s\n' "$2" 'pr13-activation-72h-v4.sha256' | sha256sum --check --strict -
sha256sum --check --strict pr13-activation-72h-v4.sha256
status=0
pr13_supervise '/opt/cpanel/ea-php84/root/usr/bin/php' "$directory/pr13-activate.php" \
 "$directory/pr13-activation-72h-v4.json" "$1" || status=$?
reported=1
exit "$status"
