<?php

namespace App\Http\Controllers\Api\Front;

use App\Enums\ContextStatus;
use App\Exceptions\PrivacyException;
use App\Http\Controllers\Controller;
use App\Http\Front\Front;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\ExpertPresenter;
use App\Http\Resources\UserResource;
use App\Models\DataRightsRequest;
use App\Models\ExpertProfile;
use App\Models\PolicyVersion;
use App\Models\SpecializedContext;
use App\Models\User;
use App\Services\Privacy\ConsentManager;
use App\Services\Privacy\ContextManager;
use App\Services\Privacy\DataRightsManager;
use App\Services\Privacy\ProfileManager;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The User's account area for the v7 dashboard: profile, preferences, consents, data requests, contexts. */
class UserAccountController extends Controller
{
    use FrontResponses;

    /** v7 consent purposes ↔ API purposes. `expert_sharing` is recorded per brief approval; the toggle gates new briefs. */
    private const PURPOSES = ['marketing' => 'marketing', 'ai_processing' => 'ai_assistance', 'expert_sharing' => 'expert_sharing'];

    private function user(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    public static function profile(User $u): array
    {
        $p = $u->profile;

        return ['id' => $u->id, 'name' => $u->name, 'fullName' => $u->name, 'email' => $u->email, 'emailVerified' => $u->hasVerifiedEmail(), 'phone' => $p?->phone,
            'country' => $p?->country, 'language' => $p?->language ?? 'ar', 'timezone' => $p?->timezone ?? 'Asia/Dubai', 'version' => $p?->version ?? 0, 'memberSince' => Front::iso($u->created_at)];
    }

    /** GET /api/user: the v7 profile fields at the top level, plus `user` for the session restore (AuthContext). */
    public function me(Request $r): JsonResponse
    {
        $u = $this->user($r);

        return $this->ok(self::profile($u) + ['user' => (new UserResource($u))->resolve($r), 'email_verified' => $u->hasVerifiedEmail()], 'Authenticated user retrieved successfully.');
    }

    public function show(Request $r): JsonResponse
    {
        return $this->ok(self::profile($this->user($r)));
    }

    public function update(Request $r, ProfileManager $profiles): JsonResponse
    {
        $d = $r->validate(['version' => ['sometimes', 'integer', 'min:0'], 'expectedVersion' => ['sometimes', 'integer', 'min:0'], 'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{6,32}$/'], 'country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(config('countries'))],
            'language' => ['sometimes', 'nullable', Rule::in(config('privacy.languages'))], 'timezone' => ['sometimes', 'nullable', 'timezone:all']]);
        $u = $this->user($r);
        $d['expectedVersion'] = (int) ($d['expectedVersion'] ?? $d['version'] ?? ($u->profile?->version ?? 0));
        unset($d['version']);
        $profiles->update($u, $d);

        return $this->ok(self::profile($u->fresh()), 'Your details were saved.');
    }

    private function preferenceShape(User $u, ProfileManager $profiles): array
    {
        $p = $profiles->preferences($u);

        return ['language' => $u->profile?->language ?? 'ar', 'theme' => 'light', 'emailNotifications' => in_array('email', $p->contact_channels ?? [], true),
            'aiAssistanceEnabled' => (bool) $p->ai_assistance_enabled, 'contactChannels' => $p->contact_channels ?? [], 'version' => (int) $p->version];
    }

    public function preferences(Request $r, ProfileManager $profiles): JsonResponse
    {
        return $this->ok($this->preferenceShape($this->user($r), $profiles));
    }

    public function updatePreferences(Request $r, ProfileManager $profiles): JsonResponse
    {
        $d = $r->validate(['version' => ['sometimes', 'integer', 'min:0'], 'emailNotifications' => ['sometimes', 'boolean'], 'aiAssistanceEnabled' => ['sometimes', 'boolean'],
            'language' => ['sometimes', Rule::in(config('privacy.languages'))], 'theme' => ['sometimes', 'string', 'max:10']]);
        $u = $this->user($r);
        $current = $profiles->preferences($u);
        $data = ['expectedVersion' => (int) ($d['version'] ?? $current->version)];
        if (array_key_exists('emailNotifications', $d)) {
            $channels = array_values(array_diff($current->contact_channels ?? [], ['email']));
            $data['contactChannels'] = $d['emailNotifications'] ? array_merge(['email'], $channels) : $channels;
        }
        if (array_key_exists('aiAssistanceEnabled', $d)) {
            $data['aiAssistanceEnabled'] = $d['aiAssistanceEnabled'];
        }
        $profiles->updatePreferences($u, $data);
        if (isset($d['language']) && $d['language'] !== $u->profile?->language) {
            $profiles->update($u, ['expectedVersion' => (int) ($u->profile?->version ?? 0), 'language' => $d['language']]);
        }

        return $this->ok($this->preferenceShape($u->fresh(), $profiles));
    }

    /* ───────── consents ───────── */

    public function consents(Request $r, ConsentManager $consents): JsonResponse
    {
        $all = collect($consents->summaries($this->user($r)))->keyBy('purpose');
        $out = [];
        foreach (self::PURPOSES as $front => $api) {
            $s = $all->get($api);
            $decision = $s['decision'] ?? 'not_recorded';
            // Not recorded: expert sharing happens only through an explicit brief approval, so it reads as allowed.
            if ($decision === 'not_recorded') {
                $decision = $front === 'expert_sharing' ? 'granted' : 'declined';
            }
            $out[] = ['purpose' => $front, 'decision' => $decision, 'decidedAt' => $s['decidedAt'] ?? null, 'requiresReconsent' => $s['requiresReconsent'] ?? false];
        }

        return $this->ok($out);
    }

    public function decide(Request $r, ConsentManager $consents): JsonResponse
    {
        $d = $r->validate(['purpose' => ['required', Rule::in(array_keys(self::PURPOSES))], 'decision' => ['required', Rule::in(['granted', 'declined', 'withdrawn'])]]);
        $u = $this->user($r);
        $purpose = self::PURPOSES[$d['purpose']];
        $policy = $consents->currentPolicy($purpose, Front::lang()) ?? $consents->currentPolicy($purpose, 'ar') ?? $consents->currentPolicy($purpose, 'en');
        if (! $policy) {
            throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'No published policy exists for this purpose yet.', 503);
        }
        $latest = $u->consents()->where('purpose', $purpose)->orderByDesc('id')->first();
        $decision = $d['decision'];
        if ($decision === 'withdrawn' || $decision === 'declined') {
            $decision = $latest?->decision?->value === 'granted' ? 'withdrawn' : 'declined';
        }
        $payload = ['purpose' => $purpose, 'decision' => $decision, 'policyVersionId' => $decision === 'withdrawn' ? $latest->policy_version_id : $policy->id, 'previousRecordId' => $latest?->id];
        $consents->decide($u, $payload);
        if ($purpose === 'ai_assistance') {
            $prefs = app(ProfileManager::class);
            $prefs->updatePreferences($u, ['expectedVersion' => (int) $prefs->preferences($u)->version, 'aiAssistanceEnabled' => $decision === 'granted']);
        }

        return $this->ok(['purpose' => $d['purpose'], 'decision' => $decision === 'declined' && $d['decision'] === 'withdrawn' ? 'withdrawn' : $decision]);
    }

    /* ───────── data requests ───────── */

    private static function dataRequest(DataRightsRequest $q): array
    {
        return ['id' => $q->id, 'type' => $q->type?->value ?? $q->type, 'status' => $q->status?->value ?? $q->status, 'requestedAt' => Front::iso($q->requested_at), 'dueAt' => Front::iso($q->due_at),
            'completedAt' => Front::iso($q->completed_at ?? null), 'version' => $q->version, 'downloadAvailable' => ($q->status?->value ?? $q->status) === 'completed' && ($q->type?->value ?? $q->type) === 'export'];
    }

    public function dataRequests(Request $r): JsonResponse
    {
        return $this->ok($this->user($r)->dataRequests()->orderByDesc('id')->get()->map(fn ($q) => self::dataRequest($q))->all());
    }

    /**
     * Export / deletion requests need a recent authentication: a login within the confirmation window, or the current password.
     */
    public function requestData(Request $r, DataRightsManager $manager): JsonResponse
    {
        $d = $r->validate(['type' => ['required', Rule::in(['export', 'deletion'])], 'password' => ['sometimes', 'string', 'max:200']]);
        $u = $this->user($r);
        $token = $u->currentAccessToken();
        $fresh = $token && $token->created_at && $token->created_at->gt(now()->subMinutes(max(30, (int) config('privacy.confirmation_minutes'))));
        if (! $fresh) {
            if (! isset($d['password']) || ! Hash::check($d['password'], $u->password)) {
                throw new PrivacyException('REAUTHENTICATION_REQUIRED', Front::t(['ar' => 'لأمان حسابك، سجّل الدخول من جديد ثم أعد الطلب.', 'en' => 'For your security, sign in again and repeat the request.']), 403);
            }
        }

        return $this->ok(self::dataRequest($manager->create($u, ['type' => $d['type']], $fresh ? $token->created_at : now())), 'We received your request.', 201);
    }

    /* ───────── contexts (saved background reused across cases) ───────── */

    private static function context(SpecializedContext $c): array
    {
        $c->loadMissing('latestVersion');
        $payload = $c->latestVersion?->payload ?? [];
        $notes = collect($payload['facts'] ?? [])->firstWhere('key', 'background')['value'] ?? '';

        return ['id' => $c->id, 'name' => $payload['title'] ?? '', 'notes' => $notes, 'status' => $c->status->value, 'version' => $c->current_version, 'domain' => $c->domain,
            'country' => $c->country, 'updatedAt' => Front::iso($c->updated_at)];
    }

    public function contexts(Request $r): JsonResponse
    {
        return $this->ok($this->user($r)->contexts()->where('status', '!=', 'deleted')->orderByDesc('updated_at')->get()->map(fn ($c) => self::context($c))->all());
    }

    public function createContext(Request $r, ContextManager $contexts): JsonResponse
    {
        $d = $r->validate(['name' => ['required', 'string', 'min:1', 'max:160'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'domain' => ['sometimes', Rule::in(array_keys(config('context_schemas.domains')))], 'country' => ['sometimes', 'string', 'size:2', Rule::in(config('countries'))]]);
        $u = $this->user($r);
        $notes = trim((string) ($d['notes'] ?? '')) ?: $d['name'];
        $c = $contexts->create($u, ['domain' => $d['domain'] ?? 'business', 'country' => $d['country'] ?? ($u->profile?->country ?: 'AE'), 'title' => $d['name'],
            'facts' => [['key' => 'background', 'value' => $notes, 'source' => 'user', 'effectiveDate' => now()->toDateString()]], 'allowCaseReuse' => true]);

        return $this->ok(self::context($c), 'Context saved.', 201);
    }

    private function transitionContext(Request $r, int $id, ContextManager $contexts, ContextStatus $to): SpecializedContext
    {
        $u = $this->user($r);
        $row = $u->contexts()->findOrFail($id);
        $version = (int) ($r->input('expectedVersion') ?? $row->current_version);

        return $contexts->change($u, $id, ['expectedVersion' => $version], $to);
    }

    public function archiveContext(Request $r, int $context, ContextManager $contexts): JsonResponse
    {
        return $this->ok(self::context($this->transitionContext($r, $context, $contexts, ContextStatus::Archived)));
    }

    public function restoreContext(Request $r, int $context, ContextManager $contexts): JsonResponse
    {
        return $this->ok(self::context($this->transitionContext($r, $context, $contexts, ContextStatus::Active)));
    }

    public function deleteContext(Request $r, int $context, ContextManager $contexts): JsonResponse
    {
        $this->transitionContext($r, $context, $contexts, ContextStatus::Deleted);

        return $this->ok(null, 'Context deleted.');
    }

    /* ───────── public expert reviews ───────── */

    public function expertReviews(string $slug, ExpertReviews $reviews): JsonResponse
    {
        $profile = ExpertProfile::where('slug', $slug)->where('is_published', true)->firstOrFail();
        $s = $reviews->summary($profile->expert_id);
        $d = $s['ratingDistribution'];

        return $this->ok(['rating' => $s['averageRating'] ?? 0, 'count' => $s['reviewCount'], 'distribution' => [(int) $d['5'], (int) $d['4'], (int) $d['3'], (int) $d['2'], (int) $d['1']],
            'reviews' => ExpertPresenter::reviews($profile->expert_id, 50)]);
    }
}
