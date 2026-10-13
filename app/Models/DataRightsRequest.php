<?php

namespace App\Models;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataRightsRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => DataRequestType::class, 'status' => DataRequestStatus::class, 'version' => 'integer', 'outcome' => 'array', 'identity_confirmed_at' => 'datetime', 'requested_at' => 'datetime', 'due_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'artifact_expires_at' => 'datetime', 'processing_lease_until' => 'datetime'];
    }

    protected $hidden = ['artifact_disk', 'artifact_path', 'processing_token'];

    public function items(): HasMany
    {
        return $this->hasMany(DataRightsRequestItem::class, 'request_id');
    }
}
