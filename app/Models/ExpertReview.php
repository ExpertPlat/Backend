<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Service Review of an Expert (docs/sprint4/reviews-contract.md). Only `published` rows are public. The author, Expert, Case and
 * Agreement references never change; reviews are not hard-deleted without an approved retention policy.
 */
class ExpertReview extends Model
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const CHALLENGED = 'challenged';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::PENDING => [self::PUBLISHED, self::HIDDEN],
        self::PUBLISHED => [self::HIDDEN, self::CHALLENGED],
        self::CHALLENGED => [self::PUBLISHED, self::HIDDEN],
        self::HIDDEN => [self::PUBLISHED],
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $r): void {
            if (array_intersect(array_keys($r->getDirty()), ['expert_id', 'user_id', 'case_id', 'agreement_id']) !== []) {
                throw new LogicException('A Review reference cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Reviews require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['rating' => 'integer', 'version' => 'integer', 'moderation_note' => 'encrypted', 'moderated_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('moderation_status', self::PUBLISHED);
    }

    /** @return BelongsTo<Expert, $this> */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }
}
