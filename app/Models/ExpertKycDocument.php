<?php

namespace App\Models;

use App\Enums\ExpertKycDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertKycDocument extends Model
{
    protected $fillable = [
        'document_type',
        'qualification_id',
        'credential_id',
        'disk',
        'path',
        'original_name',
        'extension',
        'mime_type',
        'size',
        'checksum',
        'sort_order',
    ];

    protected $hidden = ['disk', 'path', 'checksum'];

    protected function casts(): array
    {
        return [
            'document_type' => ExpertKycDocumentType::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'application_id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(ExpertKycQualification::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(ExpertKycCredential::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
