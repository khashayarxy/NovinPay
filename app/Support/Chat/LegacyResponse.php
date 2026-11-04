<?php

namespace App\Support\Chat;

/** Internal response boundary, never an authentication or permission exemption. */
final class LegacyResponse extends \RuntimeException
{
    public function __construct(public readonly ?array $payload = null, public readonly int $status = 200)
    {
        parent::__construct('chat_response');
    }
}
