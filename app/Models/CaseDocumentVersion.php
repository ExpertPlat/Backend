<?php

namespace App\Models;

use App\Enums\CaseDocumentScanStatus;
use Illuminate\Database\Eloquent\Model;

class CaseDocumentVersion extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'version' => 'integer', 'size' => 'integer', 'scan_status' => CaseDocumentScanStatus::class, 'scanned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['scan_status', 'scan_reason', 'scanned_at', 'updated_at'])) {
                throw new \LogicException('Document revision content is immutable.');
            }
        });
    }
}
