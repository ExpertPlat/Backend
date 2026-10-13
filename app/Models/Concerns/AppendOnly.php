<?php

namespace App\Models\Concerns;

use LogicException;

trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException('Historical records cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Historical records require an approved retention process.'));
    }
}
