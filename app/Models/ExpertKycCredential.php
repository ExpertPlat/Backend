<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExpertKycCredential extends Model
{
    protected $fillable = [
        'type',
        'name',
        'issuer',
        'issue_date',
        'expiry_date',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'application_id');
    }

    public function document(): HasOne
    {
        return $this->hasOne(ExpertKycDocument::class, 'credential_id');
    }
}
