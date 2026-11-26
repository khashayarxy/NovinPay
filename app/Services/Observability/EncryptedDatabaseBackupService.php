<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

final class EncryptedDatabaseBackupService
{
    public const MAGIC = 'NPDBENC1';

    public const AAD = 'NOVINPAY-SCHEDULED-DB-BACKUP-v1';

    private const PBKDF2_ITERATIONS = 100_000;

    private const TELEGRAM_MAX_DOCUMENT_BYTES = 48 * 1024 * 1024;

    public function __construct(
        private readonly ObservabilityMetrics $metrics,
        private readonly TelegramTopicNotifier $topics,
    ) {}

    /**
     * Create a gzipped, AES-256-GCM encrypted SQL dump, prune old scheduled dumps,
     * and upload it to the Telegram `backups` topic.
     *
     * @return array<string, mixed>
     */
    public function runAndSend(bool $force = false): array
    {
        $baseDir = rtrim((string) config('observability.backups.path', storage_path('app/private/backups')), '/\\');
        if ($baseDir === '') {
            $baseDir = storage_path('app/private/backups');
        }
        $scheduledDir = $baseDir.DIRECTORY_SEPARATOR.'scheduled';
        $now = now('Asia/Tehran');
        $fileName = 'novinpay-db-'.$now->format('Ymd-His').'.sql.gz.enc';
        $targetPath = $scheduledDir.DIRECTORY_SEPARATOR.$fileName;

        try {
            $this->ensurePrivateDirectory($baseDir);
            $this->ensurePrivateDirectory($scheduledDir);

            [$passphrase, $keySource] = $this->resolveKeyMaterial();
            $sqlBytes = $this->exportDatabaseSql();
            if ($sqlBytes === '') {
                throw new RuntimeException('Database SQL export produced empty output.');
            }

            $gzipped = gzencode($sqlBytes, 9);
            unset($sqlBytes);
            if (! is_string($gzipped) || $gzipped === '') {
                throw new RuntimeException('Failed to gzip SQL dump.');
            }

            $encrypted = $this->encryptBytes($gzipped, $passphrase);
            unset($gzipped, $passphrase);

            $this->writePrivateFile($targetPath, $encrypted);
            $sizeBytes = strlen($encrypted);
            $sha256 = hash('sha256', $encrypted);
            unset($encrypted);

            $retain = max(2, (int) config('observability.backups.retain_scheduled', 6));
            $pruned = $this->pruneOldScheduledBackups($scheduledDir, $retain);

            $sizeMb = round($sizeBytes / 1048576, 2);
            $captionLines = [
                '🔐 بکاپ رمزنگاری‌شده خودکار دیتابیس نوین‌پی',
                'زمان (تهران): '.$now->format('Y-m-d H:i:s'),
                'فایل: '.$fileName,
                'حجم: '.$sizeMb.' مگابایت',
                'الگوریتم: AES-256-GCM + Gzip',
                'کلید رمزنگاری: '.$keySource,
                'SHA-256: '.substr($sha256, 0, 24).'…',
            ];
            $caption = implode("\n", $captionLines);
            $dedupeKey = $force ? null : 'scheduled_db_backup:'.$now->format('YmdH');

            if ($sizeBytes <= self::TELEGRAM_MAX_DOCUMENT_BYTES) {
                $sent = $this->topics->sendDocument('backups', $targetPath, $caption, $fileName, $dedupeKey);
            } else {
                $sent = $this->topics->send(
                    'backups',
                    $caption."\n⚠️ حجم فایل بیش از سقف ارسال مستقیم ربات تلگرام است؛ نسخه محلی در مسیر امن سرور ذخیره شد.",
                    $dedupeKey,
                );
            }

            $snapshot = [
                'status' => 'ok',
                'file' => $fileName,
                'path' => $targetPath,
                'size_bytes' => $sizeBytes,
                'size_mb' => $sizeMb,
                'sha256' => $sha256,
                'key_source' => $keySource,
                'pruned_old_files' => $pruned,
                'modified_at' => now()->toIso8601String(),
                'age_hours' => 0.0,
                'max_age_hours' => max(1, (int) config('observability.backups.max_age_hours', 72)),
                'notification_sent' => $sent,
                'checked_at' => now()->toIso8601String(),
            ];

            $this->metrics->storeLatestBackup($snapshot);
            Log::channel('daily')->info('Scheduled encrypted database backup completed', [
                'file' => $fileName,
                'size_bytes' => $sizeBytes,
                'sha256' => $sha256,
                'key_source' => $keySource,
                'telegram_sent' => $sent,
                'pruned' => $pruned,
            ]);

            return $snapshot;
        } catch (Throwable $e) {
            $reason = mb_substr(preg_replace('/\s+/u', ' ', trim($e->getMessage())) ?? 'Backup failed', 0, 180);
            $snapshot = [
                'status' => 'failed',
                'file' => $fileName,
                'size_bytes' => null,
                'size_mb' => null,
                'modified_at' => null,
                'age_hours' => null,
                'max_age_hours' => max(1, (int) config('observability.backups.max_age_hours', 72)),
                'note' => $e::class,
                'notification_sent' => false,
                'checked_at' => now()->toIso8601String(),
            ];

            try {
                $this->metrics->storeLatestBackup($snapshot);
                Log::channel('daily')->error('Scheduled encrypted database backup failed', [
                    'exception' => $e::class,
                    'reason' => $reason,
                ]);
                $alert = "🚨 خطا در بکاپ خودکار دیتابیس نوین‌پی\nزمان: ".$now->format('Y-m-d H:i:s')."\nنوع خطا: ".$e::class;
                $this->topics->send('backups', $alert, $force ? null : 'scheduled_db_backup_fail:'.$now->format('YmdH'));
                $this->topics->send('errors', $alert, 'scheduled_db_backup_err:'.$now->format('YmdH'));
            } catch (Throwable) {
            }

            return $snapshot;
        }
    }

    public function encryptBytes(string $plaintext, string $passphrase): string
    {
        if ($passphrase === '') {
            throw new RuntimeException('Encryption passphrase cannot be empty.');
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return self::MAGIC.$salt.$iv.$tag.$ciphertext;
    }

    public function decryptArchive(string $encryptedPath, ?string $passphrase = null): string
    {
        if (! is_file($encryptedPath) || is_link($encryptedPath)) {
            throw new RuntimeException('Encrypted backup archive not found.');
        }

        $raw = (string) file_get_contents($encryptedPath);
        if (strlen($raw) <= 52 || substr($raw, 0, 8) !== self::MAGIC) {
            throw new RuntimeException('Invalid encrypted backup header.');
        }

        $secret = $passphrase ?? $this->resolveKeyMaterial()[0];
        $salt = substr($raw, 8, 16);
        $iv = substr($raw, 24, 12);
        $tag = substr($raw, 36, 16);
        $ciphertext = substr($raw, 52);

        $key = hash_pbkdf2('sha256', $secret, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $gzipped = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if (! is_string($gzipped)) {
            throw new RuntimeException('Decryption failed or authentication tag mismatch.');
        }

        $sql = @gzdecode($gzipped);
        if (! is_string($sql)) {
            throw new RuntimeException('Failed to gunzip decrypted SQL archive.');
        }

        return $sql;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveKeyMaterial(): array
    {
        $passphrase = trim((string) config('observability.backups.passphrase', ''));
        if ($passphrase !== '') {
            return [$passphrase, 'passphrase_env'];
        }

        $keyFile = trim((string) config('observability.backups.key_file', ''));
        if ($keyFile !== '' && ! is_link($keyFile) && is_file($keyFile) && is_readable($keyFile)) {
            $bytes = trim((string) file_get_contents($keyFile));
            if ($bytes !== '') {
                return [$bytes, 'key_file'];
            }
        }

        $appKey = trim((string) config('app.key', ''));
        if ($appKey !== '') {
            return [$appKey, 'app_key_fallback'];
        }

        throw new RuntimeException('No backup encryption key configured.');
    }

    private function exportDatabaseSql(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $native = $this->tryNativeMysqlDump($connection->getConfig());
            if ($native !== null && $native !== '') {
                return $native;
            }

            return $this->exportMysqlViaPdo($connection->getPdo(), (string) $connection->getDatabaseName());
        }

        if ($driver === 'sqlite') {
            return $this->exportSqliteViaPdo($connection->getPdo());
        }

        throw new RuntimeException("Unsupported database driver for backup: {$driver}");
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function tryNativeMysqlDump(array $cfg): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $database = (string) ($cfg['database'] ?? '');
        $username = (string) ($cfg['username'] ?? '');
        if ($database === '' || $username === '') {
            return null;
        }

        $binary = null;
        foreach (['/usr/bin/mysqldump', '/usr/bin/mariadb-dump', '/usr/local/bin/mysqldump'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            return null;
        }

        $tmpDefaults = tempnam(sys_get_temp_dir(), 'npdbcnf_');
        if (! is_string($tmpDefaults)) {
            return null;
        }

        try {
            @chmod($tmpDefaults, 0600);
            $quote = static fn (string $v): string => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $v).'"';
            $host = (string) ($cfg['host'] ?? '127.0.0.1');
            $port = (int) ($cfg['port'] ?? 3306);
            $password = (string) ($cfg['password'] ?? '');
            $socket = (string) ($cfg['unix_socket'] ?? '');

            $cnf = "[client]\nuser=".$quote($username)."\npassword=".$quote($password)."\ndefault-character-set=utf8mb4\n";
            if ($socket !== '') {
                $cnf .= 'socket='.$quote($socket)."\n";
            } else {
                $cnf .= 'host='.$quote($host)."\nport=".$port."\n";
            }
            file_put_contents($tmpDefaults, $cnf);

            $cmd = [
                $binary,
                '--defaults-extra-file='.$tmpDefaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--skip-comments',
                $database,
            ];

            $proc = @proc_open($cmd, [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);

            if (! is_resource($proc)) {
                return null;
            }

            $sql = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            return $exit === 0 && is_string($sql) && str_contains($sql, 'CREATE TABLE') ? $sql : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmpDefaults);
        }
    }

    private function exportMysqlViaPdo(PDO $pdo, string $dbName): string
    {
        $out = "-- NovinPay Encrypted Database Backup (MySQL/MariaDB PDO)\n";
        $out .= '-- Generated UTC: '.gmdate('Y-m-d\TH:i:s\Z')."\n";
        $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $row) {
                $table = (string) $row[0];
                $quoted = '`'.str_replace('`', '``', $table).'`';
                $createRow = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
                $createSql = (string) ($createRow[1] ?? '');
                $out .= "DROP TABLE IF EXISTS {$quoted};\n{$createSql};\n";

                $stmt = $pdo->query("SELECT * FROM {$quoted}");
                while (($record = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $cols = array_map(static fn (string $c): string => '`'.str_replace('`', '``', $c).'`', array_keys($record));
                    $vals = array_map(fn ($v): string => $this->quoteSqlValue($pdo, $v), array_values($record));
                    $out .= "INSERT INTO {$quoted} (".implode(',', $cols).') VALUES ('.implode(',', $vals).");\n";
                }
                $out .= "\n";
            }

            $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($triggers as $trigger) {
                $trigName = (string) ($trigger['Trigger'] ?? '');
                if ($trigName === '') {
                    continue;
                }
                $quotedTrig = '`'.str_replace('`', '``', $trigName).'`';
                $createTrig = $pdo->query("SHOW CREATE TRIGGER {$quotedTrig}")->fetch(PDO::FETCH_ASSOC);
                $origSql = (string) ($createTrig['SQL Original Statement'] ?? '');
                if ($origSql !== '') {
                    $out .= "DROP TRIGGER IF EXISTS {$quotedTrig};\n{$origSql};\n\n";
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return $out;
    }

    private function exportSqliteViaPdo(PDO $pdo): string
    {
        $out = "-- NovinPay Encrypted Database Backup (SQLite PDO)\n";
        $out .= '-- Generated UTC: '.gmdate('Y-m-d\TH:i:s\Z')."\n";
        $out .= "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n";

        $tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as $tableRow) {
            $table = (string) $tableRow['name'];
            $createSql = (string) ($tableRow['sql'] ?? '');
            if ($createSql === '') {
                continue;
            }
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $out .= "DROP TABLE IF EXISTS {$quoted};\n{$createSql};\n";

            $stmt = $pdo->query("SELECT * FROM {$quoted}");
            while (($record = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cols = array_map(static fn (string $c): string => '"'.str_replace('"', '""', $c).'"', array_keys($record));
                $vals = array_map(fn ($v): string => $this->quoteSqlValue($pdo, $v), array_values($record));
                $out .= "INSERT INTO {$quoted} (".implode(',', $cols).') VALUES ('.implode(',', $vals).");\n";
            }
            $out .= "\n";
        }

        $out .= "COMMIT;\nPRAGMA foreign_keys=ON;\n";

        return $out;
    }

    private function quoteSqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    private function ensurePrivateDirectory(string $dir): void
    {
        for ($cur = $dir; $cur !== dirname($cur); $cur = dirname($cur)) {
            if (is_link($cur)) {
                throw new RuntimeException('Backup path cannot traverse symbolic links.');
            }
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create private backup directory.');
        }
        @chmod($dir, 0700);
    }

    private function writePrivateFile(string $path, string $bytes): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing to write backup through symlink.');
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(8));
        $fh = @fopen($tmp, 'xb');
        if (! is_resource($fh)) {
            throw new RuntimeException('Unable to create temporary backup file.');
        }
        try {
            @chmod($tmp, 0600);
            if (fwrite($fh, $bytes) !== strlen($bytes) || ! fflush($fh)) {
                throw new RuntimeException('Incomplete write to backup archive.');
            }
        } finally {
            fclose($fh);
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to finalize backup archive.');
        }
        @chmod($path, 0600);
    }

    private function pruneOldScheduledBackups(string $dir, int $retain): int
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'novinpay-db-*.sql.gz.enc') ?: [];
        $valid = [];
        foreach ($files as $file) {
            if (is_file($file) && ! is_link($file)) {
                $valid[$file] = (int) (@filemtime($file) ?: 0);
            }
        }
        arsort($valid);
        $pruned = 0;
        foreach (array_slice(array_keys($valid), $retain) as $oldFile) {
            if (@unlink($oldFile)) {
                $pruned++;
            }
        }

        return $pruned;
    }
}
