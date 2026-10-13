<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseContextSnapshot extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['detached_at'])) {
                throw new \LogicException('Context snapshot content is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Snapshots require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'selected_fact_keys' => 'array', 'authorized_at' => 'datetime', 'detached_at' => 'datetime'];
    }
}
