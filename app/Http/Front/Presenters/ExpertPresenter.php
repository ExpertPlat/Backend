<?php

namespace App\Http\Front\Presenters;

use App\Enums\ExpertScopeStatus;
use App\Models\Booking;
use App\Models\CatalogNode;
use App\Models\Expert;
use App\Models\ExpertReview;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Support\Facades\Storage;

/** Public Expert card (services/mock/presenters.js publicExpert) — only what a User may see. */
final class ExpertPresenter
{
    private const LANGUAGES = ['ar' => ['ar' => 'العربية', 'en' => 'Arabic'], 'en' => ['ar' => 'الإنجليزية', 'en' => 'English'], 'fr' => ['ar' => 'الفرنسية', 'en' => 'French'], 'tr' => ['ar' => 'التركية', 'en' => 'Turkish']];

    /** @var array<int, array> */
    private static array $reputation = [];

    public static function avatar(Expert $e): ?string
    {
        $p = $e->profile;

        return $p?->avatar_path ? Storage::disk($p->avatar_disk ?: 'public')->url($p->avatar_path) : null;
    }

    public static function country(?string $code): mixed
    {
        if (! $code) {
            return null;
        }

        return CatalogNode::where('kind', 'country')->where('code', $code)->value('labels') ?? $code;
    }

    /** @param list<int> $ids */
    public static function warm(array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys(self::$reputation)));
        if ($missing) {
            self::$reputation += app(ExpertReviews::class)->summaries($missing);
        }
    }

    public static function public(?Expert $e, bool $withReviews = false): ?array
    {
        if (! $e) {
            return null;
        }
        $e->loadMissing(['profile', 'availability']);
        self::warm([$e->id]);
        $rep = self::$reputation[$e->id];
        $scope = $e->verifiedScopes()->where('status', ExpertScopeStatus::Active)->orderBy('id')->first();
        $hours = $e->availability?->response_time_hours;
        $out = [
            'id' => $e->id, 'slug' => $e->profile?->slug, 'name' => $e->name, 'title' => $e->profile?->professional_title, 'location' => self::country($e->country),
            'isDemo' => (bool) $e->is_demo, 'country' => $e->country, 'specialty' => $scope?->domain ?? $e->domain, 'avatarUrl' => self::avatar($e),
            'rating' => $rep['averageRating'] ?? 0, 'reviewsCount' => $rep['reviewCount'], 'yearsExperience' => $e->profile?->years_experience,
            'completedSessions' => Booking::where('expert_id', $e->id)->where('status', 'completed')->count(),
            'languages' => array_values(array_map(fn ($l) => self::LANGUAGES[$l] ?? $l, $e->profile?->public_languages ?? [])),
            'responseTime' => $hours ? ($hours <= 24 ? ['ar' => "خلال {$hours} ساعة", 'en' => "Within {$hours} hours"] : ['ar' => 'خلال '.intdiv($hours, 24).' أيام', 'en' => 'Within '.intdiv($hours, 24).' days']) : null,
        ];
        if ($withReviews) {
            $d = $rep['ratingDistribution'];
            $out['ratingDistribution'] = [(int) $d['5'], (int) $d['4'], (int) $d['3'], (int) $d['2'], (int) $d['1']];
            $out['reviews'] = self::reviews($e->id, 5);
        }

        return $out;
    }

    /** Lowest total of the Expert's recent proposals (minor units), when no "from" price is set on the offering. */
    public static function typicalPrice(int $expertId): ?int
    {
        $min = \App\Models\ProposalVersion::query()->whereIn('proposal_id', \App\Models\Proposal::where('expert_id', $expertId)->select('id'))->min('total_amount_minor');

        return $min !== null ? (int) $min : null;
    }

    public static function reviews(int $expertId, int $limit = 20): array
    {
        return ExpertReview::query()->published()->where('expert_id', $expertId)->with('user:id,name')->latest('published_at')->limit($limit)->get()
            ->map(fn (ExpertReview $r) => ['id' => $r->id, 'reviewerName' => self::initials($r->user?->name), 'rating' => $r->rating, 'createdAt' => \App\Http\Front\Front::iso($r->published_at ?? $r->created_at), 'body' => $r->body])->all();
    }

    /** "Mona Hassan" → "Mona H." — reviewers are never fully named publicly. */
    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        if (! $parts || $parts[0] === '') {
            return '—';
        }

        return $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : '');
    }
}
