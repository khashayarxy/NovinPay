<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\quotaEnvelope;
use function NovinPay\Release\quotaMailbox;
use function NovinPay\Release\writeNew;

require_once __DIR__.'/../../scripts/release/runtime.php';

class QuotaMailboxTest extends TestCase
{
    private function request(): array
    {
        return ['format' => 2, 'manifestSha256' => str_repeat('a', 64), 'sourceCommit' => str_repeat('b', 40),
            'targetCommit' => str_repeat('c', 40), 'phase' => 'fence', 'nonce' => str_repeat('d', 32)];
    }

    private function envelope(array $proof): string
    {
        $body = json_encode($proof, JSON_THROW_ON_ERROR);

        return json_encode(['format' => 2, 'body' => base64_encode($body), 'sha256' => hash('sha256', $body)], JSON_THROW_ON_ERROR);
    }

    public function test_partial_publication_is_invisible_until_whole_envelope_matches(): void
    {
        $request = $this->request();
        $pin = str_repeat('e', 64);
        $proof = $request + ['requestSha256' => $pin];
        $raw = $this->envelope($proof);
        $this->assertNull(quotaEnvelope(substr($raw, 0, -2), $request, $pin));
        $this->assertSame($proof, quotaEnvelope($raw, $request, $pin));
    }

    public function test_a_verified_response_cannot_be_replayed_against_another_request(): void
    {
        $request = $this->request();
        $pin = str_repeat('e', 64);
        $proof = $request + ['requestSha256' => $pin];
        $this->expectExceptionMessage('quota_request_binding');
        quotaEnvelope($this->envelope($proof), array_replace($request, ['nonce' => str_repeat('1', 32)]), $pin);
    }

    public function test_complete_corrupted_envelope_is_rejected(): void
    {
        $request = $this->request();
        $pin = str_repeat('e', 64);
        $raw = $this->envelope($request + ['requestSha256' => $pin]);
        $envelope = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $envelope['sha256'] = str_repeat('0', 64);
        $this->expectExceptionMessage('quota_response_hash');
        quotaEnvelope(json_encode($envelope, JSON_THROW_ON_ERROR), $request, $pin);
    }

    public function test_private_mailbox_receipts_are_created_without_overwriting_existing_evidence(): void
    {
        $root = str_replace('\\', '/', realpath(sys_get_temp_dir()).'/novinpay-quota-'.bin2hex(random_bytes(8)));
        $pin = str_repeat('a', 64);
        $path = $root.'/quota-v2-'.$pin;
        mkdir($path, 0700, true);
        $file = $path.'/ack.json';
        try {
            $this->assertSame($path, quotaMailbox($root, $pin));
            writeNew($file, ['result' => 'accepted']);
            $before = file_get_contents($file);
            try {
                writeNew($file, ['result' => 'replaced']);
                $this->fail('A previously published acknowledgment must be immutable.');
            } catch (GateFailure $failure) {
                $this->assertSame('receipt_exists', $failure->check);
            }
            $this->assertSame($before, file_get_contents($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($path);
            rmdir($root);
        }
    }
}
