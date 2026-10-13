<?php

namespace App\Providers;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\DataRightsRequest;
use App\Models\Expert;
use App\Models\ExpertOffering;
use App\Models\Proposal;
use App\Models\SpecializedContext;
use App\Models\User;
use App\Models\UserConsentRecord;
use App\Models\UserPreference;
use App\Models\UserProfile;
use App\Policies\AdminPolicy;
use App\Policies\CaseInvitationPolicy;
use App\Policies\CaseRecordPolicy;
use App\Policies\CommercePolicy;
use App\Policies\DataRightsRequestPolicy;
use App\Policies\ExpertOfferingPolicy;
use App\Policies\ExpertPolicy;
use App\Policies\RolePolicy;
use App\Policies\SpecializedContextPolicy;
use App\Policies\UserConsentRecordPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserPreferencePolicy;
use App\Policies\UserProfilePolicy;
use App\Services\Cases\ClamAvDocumentScanner;
use App\Services\Cases\DocumentScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Local demo payments: never available outside local/testing (FakeStripeGateway::enabled()).
        if (\App\Services\Payments\FakeStripeGateway::enabled()) {
            $this->app->singleton(\App\Services\Payments\StripeGateway::class, \App\Services\Payments\FakeStripeGateway::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthorization();
        RateLimiter::for('catalog-read', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('catalog-admin', fn (Request $r) => Limit::perMinute(60)->by($this->privacyKey($r)));
        Gate::policy(CaseRecord::class, CaseRecordPolicy::class);
        Gate::policy(ExpertOffering::class, ExpertOfferingPolicy::class);
        Gate::policy(CaseInvitation::class, CaseInvitationPolicy::class);
        foreach ([Proposal::class, Agreement::class, Booking::class] as $commerceModel) {
            Gate::policy($commerceModel, CommercePolicy::class);
        }
        foreach (config('payments.limits') as $operation => $limit) {
            RateLimiter::for('payments-'.$operation, fn (Request $r): Limit => Limit::perMinute($limit)->by($operation === 'webhook' ? $r->ip() : $this->privacyKey($r)));
        }
        foreach (config('commerce.limits') as $operation => $limit) {
            RateLimiter::for('commerce-'.$operation, fn (Request $r): Limit => Limit::perMinute($limit)->by($this->privacyKey($r)));
        }
        RateLimiter::for('ai-intake', fn (Request $r) => [
            Limit::perMinute((int) config('openai.intake.rate_per_minute'))->by('ai-intake-min|'.$this->privacyKey($r)),
            Limit::perHour((int) config('openai.intake.rate_per_hour'))->by('ai-intake-hour|'.$this->privacyKey($r)),
        ]);
        foreach (config('matching.limits') as $operation => $limit) {
            RateLimiter::for('matching-'.$operation, fn (Request $r): Limit => Limit::perMinute($limit)->by($this->privacyKey($r)));
        }
        foreach (config('case_intake.limits') as $operation => $limit) {
            RateLimiter::for('cases-'.$operation, fn (Request $request): Limit => Limit::perMinute($limit)->by($this->privacyKey($request)));
        }
        $this->app->bind(DocumentScanner::class, fn () => config('case_intake.fake_scan') && $this->app->environment(['local', 'testing']) && ! config('case_intake.scanner_binary')
            ? new \App\Services\Cases\LocalDocumentScanner : $this->app->make(ClamAvDocumentScanner::class));

        RateLimiter::for('privacy-read', fn (Request $request): Limit => Limit::perMinute(120)->by($this->privacyKey($request)));
        RateLimiter::for('privacy-write', fn (Request $request): Limit => Limit::perMinute(30)->by($this->privacyKey($request)));
        RateLimiter::for('privacy-request', fn (Request $request): Limit => Limit::perHour(5)->by($this->privacyKey($request)));
        RateLimiter::for('privacy-download', fn (Request $request): Limit => Limit::perMinute(10)->by($this->privacyKey($request)));
        RateLimiter::for('privacy-confirm', fn (Request $request): array => [
            Limit::perMinute(5)->by($this->privacyKey($request)),
            Limit::perMinute(20)->by('privacy-confirm-ip|'.$request->ip()),
        ]);

        RateLimiter::for('admin-login', function (Request $request): Limit {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('admin-invitation-accept', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by('admin-invitation-accept|'.$request->ip()),
            ];
        });

        RateLimiter::for('admin-management-write', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('user-verification', function (Request $request): array {
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(3)->by($userId.'|'.$request->ip()),
                Limit::perMinute(10)->by('user-verification|'.$request->ip()),
            ];
        });

        RateLimiter::for('user-password-forgot', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(3)->by($key),
                Limit::perMinute(10)->by('user-password-forgot|'.$request->ip()),
            ];
        });

        RateLimiter::for('user-password-reset', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by('user-password-reset|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-registration', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by('expert-registration|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-login', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(30)->by('expert-login|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-verification', function (Request $request): array {
            $expertId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(3)->by($expertId.'|'.$request->ip()),
                Limit::perMinute(10)->by('expert-verification|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-password-forgot', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(3)->by($key),
                Limit::perMinute(10)->by('expert-password-forgot|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-password-reset', function (Request $request): array {
            $key = $this->emailAndIpKey($request);

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by('expert-password-reset|'.$request->ip()),
            ];
        });

        RateLimiter::for('expert-kyc-write', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('expert-kyc-upload', fn (Request $request): Limit => Limit::perMinute(15)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('expert-kyc-submit', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('admin-kyc-decisions', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('expert-profile-write', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('expert-profile-avatar', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('expert-profile-publish', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($this->authenticatedKey($request)));

        RateLimiter::for('public-expert-profile', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip()));
    }

    private function registerAuthorization(): void
    {
        foreach ([
            UserProfile::class => UserProfilePolicy::class,
            UserPreference::class => UserPreferencePolicy::class,
            SpecializedContext::class => SpecializedContextPolicy::class,
            UserConsentRecord::class => UserConsentRecordPolicy::class,
            DataRightsRequest::class => DataRightsRequestPolicy::class,
        ] as $model => $policy) {
            Gate::policy($model, $policy);
        }
        Gate::policy(Admin::class, AdminPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Expert::class, ExpertPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        Gate::before(function ($user): ?bool {
            return $user instanceof Admin && $user->hasRole(AdminRole::SuperAdmin->value)
                ? true
                : null;
        });
    }

    private function emailAndIpKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        return $email.'|'.$request->ip();
    }

    private function authenticatedKey(Request $request): string
    {
        return ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip();
    }

    private function privacyKey(Request $request): string
    {
        $actor = $request->user();

        return $actor ? $actor::class.':'.$actor->getAuthIdentifier() : 'guest:'.$request->ip();
    }
}
