# Windows local connectivity and database safety

## Permanent owner acceptance environment (2026-11-06)

The active NovinPay local database is now `%LOCALAPPDATA%/NovinPay-Local/development/development.sqlite`, a consistent SQLite backup of the approved synthetic22-migration fixture, including customer_accounts. Storage/chat history/uploads and authenticated sessions live in that directory's `storage` tree. This is persistent local data, outside Git, public, Codex QA cleanup and production. Preserve it; do not reset it to rerun tests. The older14-migration isolated DB and original fixture remain untouched. No migrations were replayed.

The existing host-filtered Herd `.valet-env.php` retains legacy-host denial and returns an empty environment for unrelated hosts. Only its NovinPay branch delegates to the tracked `scripts/local/herd-environment.php` with the fixed private directory above. That loader pins SQLite database/URL, local host, private storage/cache paths and SQLite sessions; rejects absent files, symlinks/path substitution or cached config/routes/events; requires file cache and disabled external SMS/Zibal/Postex. It does not fall back to project .env. The shared entry had to change because its previous hardcoded DB overrode project settings. No routing, PHP handler, other-site configuration or project .env was changed.

Open `https://novinpaypos.test/` for the integrated customer widget, `/support-chat/support-widget.php` for its standalone view, and `/admin/login` then `/admin/support` for the operator dashboard. Synthetic owner login details are managed privately in `%LOCALAPPDATA%/NovinPay-Local/development/auth.json`; open that file locally, never paste its values into chat/Git. Root ACL permits only the current Windows user and SYSTEM, with inheritance to data files. Real customer-account OTP remains disabled: `/account/login` renders, but no live SMS/authentication bypass is supplied. Guest chat is usable; OTP account/session security is covered by mocked focused tests.

The setup is disk-backed, survives normal Herd restarts and needs no temporary guard activation. Fresh browser contexts and PHP processes were tested; a full shared Herd restart was not performed because it would interrupt other sites, and the Herd MCP transport is unavailable. Per-site dev-stack auto-start remains disabled. Do not run unguarded Artisan/queue/dev commands: the web guard is not a CLI environment loader. Use `scripts/test.ps1` for disposable tests, never run config/route cache generation or migrations against this acceptance DB.

Private `development/recovery/installation.json` records the installed shared guard hash and original backup. Before any future rollback, stop this site's test requests, reconcile the current hash, and atomically restore the verified backup; never delete either DB/storage tree. That restores the older guard and intentionally makes full chat acceptance unavailable until a reviewed replacement is installed. Never install the Herd hook or copy its private settings/database into production; the tracked loader is inert unless explicitly invoked by the local hook.

Focused validation: `./scripts/test.ps1 tests/Unit/LocalHerdEnvironmentTest.php tests/Feature/SupportChatTest.php tests/Feature/CustomerAccountTest.php`. See the latest deployment lessons for browser measurements and acceptance scope.

Verified 2026-10-23 with Windows 11, Herd 2.0.3, Happ 4.5.6.627, and canonical `https://novinpaypos.test`. The project is `C:\Users\Khashayar\Documents\NovinPay`. Use the isolated Herd site, never an unguarded `artisan serve`, `artisan dev`, queue worker or migration against the original SQLite file.

## Reproduced cause and approved correction

Windows per-user system proxy was enabled at `127.0.0.1:10809`. Its bypass list contained localhost/private IP patterns and `<local>`, but omitted the dotted NovinPay domain. Chrome failed with `ERR_CONNECTION_CLOSED`. Explicit proxy requests returned HTTP 503 / TLS handshake failure; direct HTTP reached Herd and redirected to HTTPS. Local DNS/hosts correctly mapped the domain to 127.0.0.1. Happ's xray process owned the proxy listener; no competing web server was found on Herd's 80/443 listeners.

At investigation time, the proxy process was PID 5584, `C:\Program Files\FlyFrogLLC\Happ\core\xray.exe`, parent PID 16240, `C:\Program Files\FlyFrogLLC\Happ\Happ.exe`. Both executable paths were verified through Windows limited process-image queries. Herd listener executable was `C:\Program Files\Herd\resources\app.asar.unpacked\resources\bin\nginx\nginx.exe`. PIDs are incident evidence, not stable identifiers or instructions to terminate a process.

With separate owner approval, only `novinpaypos.test;*.novinpaypos.test` was appended to `HKCU\Software\Microsoft\Windows\CurrentVersion\Internet Settings\ProxyOverride`. Existing entries, ProxyEnable, ProxyServer, Happ routing/TUN/DNS and firewall were preserved. Windows was notified through the normal WinINet settings-change/refresh calls. No browser restart was required during verification.

Chrome then loaded HTTPS without a certificate error. Eight HTTP requests returned the expected 301 to canonical HTTPS; eight HTTPS requests returned 200 while Happ remained connected. Homepage/catalogue and built Vite CSS/JS/fonts loaded. Direct Windows curl separately reports `CRYPT_E_NO_REVOCATION_CHECK` for this local CA unless per-request best-effort revocation is selected; certificate/hostname verification stays enabled. This curl-specific behavior was not Chrome's observed connection failure. Do not use `-k` or disable Windows/browser TLS protections.

The failing explicit proxy path still returns 503; the fix routes this local site around it. Happ may rewrite Windows proxy exceptions after reconnect/profile change. Recheck persistence then; no Happ-off comparison was performed because internet access was preserved. A future failure must be classified again, not assumed to be this cause.

## Herd automatic-start safety

A successful browser load triggered Herd Studio's automatic `php artisan dev` stack. The original SQLite file became locked; after stopping the identified site stack through Herd, its SHA-256 was unchanged. This is a separate CLI database-safety issue, not a port conflict or the cause of the proxy's 503.

With separate owner approval, the supported **per-site** checkbox was changed:

1. Open `https://novinpaypos.test/herd-studio`.
2. Select **More → Vite** (the panel describes the full `php artisan dev` stack).
3. Clear **Auto-start when this site opens** for `novinpaypos`.
4. Stop an already running site stack through Herd's Dev Server control; never kill arbitrary PHP/Node processes.

The checkbox was verified unchecked. Subsequent browsing used the existing `public/build` files; `public/hot` was absent, no 8000/5173 listener remained, and Herd reported dev/Vite/queue stopped. Global Herd Studio, PHP/HTTPS, Nginx, `.valet-env.php` and isolated DB/session/cache configuration were preserved. Do not re-enable auto-start until the CLI environment is also explicitly isolated. To reverse this approved setting, recheck that same site checkbox; understand the database risk first.

## Proxy rollback and manual Windows steps

The pre-change value is saved outside Git at:

`C:\Users\Khashayar\.codex\visualizations\2026\10\20\01a15969-8b3c-7783-9b3b-bade84862fc0\production-readiness-final\proxy-bypass-before.json`

For manual inspection: **Windows Settings → Network & internet → Proxy → Manual proxy setup → Edit**. Keep the existing server/port and all exception entries. The exception field must include the two NovinPay entries. Do not disable the proxy, reset networking or alter Happ routing as a troubleshooting shortcut.

To undo only this task later, remove those exact two entries in that field. If other entries have changed since the backup, preserve them. The following PowerShell restores the exact saved value only if the current list still equals the original plus these two entries; otherwise it refuses to overwrite newer edits. It restores no other registry value.

```powershell
$backupPath = Join-Path $env:USERPROFILE '.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/production-readiness-final/proxy-bypass-before.json'
$b = Get-Content -LiteralPath $backupPath -Raw | ConvertFrom-Json
$key = 'HKCU:/Software/Microsoft/Windows/CurrentVersion/Internet Settings'
$current = [string](Get-ItemProperty -LiteralPath $key).ProxyOverride
$expected = [string]$b.Original
foreach ($entry in @('novinpaypos.test', '*.novinpaypos.test')) {
    if ($entry -notin ($expected -split ';')) {
        if ($expected -and !$expected.EndsWith(';')) { $expected += ';' }
        $expected += $entry
    }
}
if ($current -ne $expected) { throw 'Proxy exceptions changed since the backup; review manually.' }
if ($b.Existed) { Set-ItemProperty -LiteralPath $key -Name ProxyOverride -Value ([string]$b.Original) }
else { Remove-ItemProperty -LiteralPath $key -Name ProxyOverride }
Add-Type -TypeDefinition 'using System; using System.Runtime.InteropServices; public static class NovinPayProxyRollback { [DllImport("wininet.dll")] public static extern bool InternetSetOption(IntPtr h, int option, IntPtr buffer, int length); }'
[void][NovinPayProxyRollback]::InternetSetOption([IntPtr]::Zero, 39, [IntPtr]::Zero, 0)
[void][NovinPayProxyRollback]::InternetSetOption([IntPtr]::Zero, 37, [IntPtr]::Zero, 0)
```

## Checks and evidence limits

- Herd Nginx/PHP 8.4 active; hosts mapping correct. 80/443 owned by Herd Nginx. No IIS/Apache conflict found. PHP FastCGI 19084 responds through the site.
- Windows Firewall remained enabled; no matching enabled application-block rule found. Firewall drop logging was off, so absence of a logged block is not proof about all historical security filtering. Defender was registered; no security protection was disabled.
- Herd's Nginx worker exit `C0000142` at 02:20:38 local time was during a Windows shutdown/restart: Event 1074 at 02:20:36, Event 6006 at 02:21:36, new boot/Event 6005 at 02:22:06. No matching Application crash records were found in the inspected interval. This does not explain the live proxy-path failure after restart.
- No reliable evidence associates every prior failure with startup or network changes. Do not attribute general DNS errors in external Herd update/telemetry calls to the hosts-mapped local site.
- Original SQLite SHA-256 stayed `466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab`. Environment/key, lockfiles and safety-config fingerprints matched the preserved baseline. Only isolated site sessions/cache may change during browser checks.

Sources: [Herd Windows troubleshooting](https://herd.laravel.com/docs/windows/troubleshooting/common-issues), [documented per-site automatic-start control](https://herd.laravel.com/docs/windows/herd-studio/dev-server), [Herd Studio settings](https://herd.laravel.com/docs/windows/herd-studio/overview).
