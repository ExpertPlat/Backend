<?php

namespace App\Services\Ai;

use RuntimeException;

/** Safe, provider-agnostic failure. The message never contains provider text, prompts or secrets. */
class AiProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?int $latencyMs = null)
    {
        parent::__construct($errorCode);
    }
}
