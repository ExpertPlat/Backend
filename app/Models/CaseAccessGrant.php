<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAccessGrant extends Model
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<CaseInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CaseInvitation::class, 'invitation_id');
    }

    /** @return BelongsTo<CaseDocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(CaseDocumentVersion::class, 'document_version_id');
    }
}
