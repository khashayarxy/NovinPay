<?php

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/lib.php';
use function NovinPay\Release\requireGate;

try {
    requireGate(PHP_SAPI === 'cli', 'cli_only', 'CLI health verification', [], 'Use the reviewed host command.');
    // This emergency endpoint always serves HTML; the deployment fence is the
    // separate component that negotiates JSON and is verified independently.
    foreach ([['GET', 'text/html'], ['HEAD', 'text/html']] as [$method,$accept]) {
        $headers = [];
        $h = curl_init('https://novinpaypos.com/maintenance.php');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: '.$accept], CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$headers) {
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
                }

                return strlen($line);
            }]);
        try {
            $body = curl_exec($h);
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($h);
        } finally {
            curl_close($h);
        }
        requireGate($errno === 0 && $code === 503, 'standalone_http', 'verified HTTPS 503 before traffic admission', ['curlCode' => $errno, 'httpCode' => $code], 'Keep the deployment fence; inspect HTTPS.');
        requireGate(($headers['retry-after'] ?? '') === '60' && str_contains(strtolower($headers['cache-control'] ?? ''), 'no-store')
            && str_contains(strtolower($headers['x-robots-tag'] ?? ''), 'noindex'), 'standalone_headers', '503 Retry-After/no-store/noindex', [], 'Keep the deployment fence.');
        if ($method === 'HEAD') {
            requireGate($body === '', 'head_body', 'empty HEAD body', [], 'Inspect the standalone response.');
        } else {
            requireGate(preg_match('~<h1[^>]*>(.*?)</h1>~s', $body, $match) === 1, 'final_heading', 'approved heading exists', [], 'Keep fence; inspect active PHP code.');
            $heading = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8')));
            requireGate($heading === 'در حال تعمیرات و به‌روزرسانی سایت نوین‌پی هستیم' && ! str_contains($body, 'np-header')
                && str_contains($body, 'tel:09384144021') && str_contains($body, 'tel:09362896373') && str_contains($body, 'data:image/webp;base64,'),
                'final_design', 'approved final standalone page served by web PHP', [], 'Keep fence; inspect source identity or app-scoped opcode cache.');
        }
    }
    echo "FINAL STANDALONE WEB PASS: HTML/HEAD and approved final design; public mode unchanged.\n";
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(\NovinPay\Release\failureReport($e, 'pre_admission_web', 'standalone')).PHP_EOL);
    exit(1);
}
