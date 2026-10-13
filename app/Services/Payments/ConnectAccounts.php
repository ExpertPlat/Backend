<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use App\Models\Expert;
use App\Models\StripeAccount;
use App\Services\Privacy\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stripe Connect association of an Expert (DR-085). Stripe-hosted onboarding only; Account Link URLs are returned to the authenticated Expert
 * in the response and are never stored, logged or emailed. Stripe onboarding does not replace SolveIt KYC, Verified Scope or jurisdiction
 * eligibility, and no transfer or payout exists in Sprint 5.
 */
class ConnectAccounts
{
    public function __construct(private StripeGateway $stripe, private FinanceNotifier $notify, private AuditWriter $audit) {}

    public function find(Expert $expert): ?StripeAccount
    {
        return StripeAccount::where('expert_id', $expert->id)->first();
    }

    /** Creates the connected account if needed and returns a fresh hosted onboarding link (this is also the "refresh expired link" action). @return array{account: StripeAccount, url: string, expiresAt: ?string} */
    public function onboardingLink(Expert $expert): array
    {
        $return = (string) config('payments.urls.connect_return');
        $refresh = (string) config('payments.urls.connect_refresh');
        if ($return === '' || $refresh === '') {
            throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The onboarding return URLs are not configured.', 503);
        }

        return DB::transaction(function () use ($expert, $return, $refresh): array {
            Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
            $account = StripeAccount::where('expert_id', $expert->id)->lockForUpdate()->first();
            if ($account === null) {
                $country = is_string($expert->country) && preg_match('/^[A-Za-z]{2}$/D', $expert->country) === 1 ? strtoupper($expert->country) : null;
                $created = $this->stripe->createAccount(array_filter([
                    'controller' => ['stripe_dashboard' => ['type' => 'express'], 'fees' => ['payer' => 'application'], 'losses' => ['payments' => 'application'], 'requirement_collection' => 'stripe'],
                    'capabilities' => ['transfers' => ['requested' => true]],
                    'email' => $expert->email,
                    'country' => $country,
                    'metadata' => ['expert_id' => (string) $expert->id],
                ]), 'expert_account_'.$expert->id);
                $account = new StripeAccount;
                $account->forceFill(['expert_id' => $expert->id, 'stripe_account_id' => $created['id'], 'onboarding_started_at' => now()]);
                try {
                    $account->save();
                } catch (UniqueConstraintViolationException) {
                    $account = StripeAccount::where('expert_id', $expert->id)->firstOrFail();
                }
                $this->audit->write($expert, 'stripe_account.created', 'stripe_account', $account->id);
            }
            $link = $this->stripe->createAccountLink(['account' => $account->stripe_account_id, 'refresh_url' => $refresh, 'return_url' => $return, 'type' => 'account_onboarding',
                'collection_options' => ['fields' => 'eventually_due']]);
            if ($account->onboarding_started_at === null) {
                $account->forceFill(['onboarding_started_at' => now()])->save();
            }

            return ['account' => $account, 'url' => (string) $link['url'], 'expiresAt' => isset($link['expires_at']) ? CarbonImmutable::createFromTimestampUTC((int) $link['expires_at'])->toISOString() : null];
        });
    }

    /** Pulls the authoritative account state (the onboarding return URL is NOT proof of completion). */
    public function sync(Expert $expert): ?StripeAccount
    {
        $account = $this->find($expert);
        if ($account === null) {
            return null;
        }
        $this->apply($account->id, $this->stripe->retrieveAccount($account->stripe_account_id));

        return $account->refresh();
    }

    /** Webhook/sync entry: refetch by Stripe account id. */
    public function syncByStripeId(string $stripeAccountId): bool
    {
        $account = StripeAccount::where('stripe_account_id', $stripeAccountId)->first();
        if ($account === null) {
            return false;
        }
        $this->apply($account->id, $this->stripe->retrieveAccount($stripeAccountId));

        return true;
    }

    /** @param array<string, mixed> $s */
    private function apply(int $accountId, array $s): void
    {
        DB::transaction(function () use ($accountId, $s): void {
            $account = StripeAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $req = (array) ($s['requirements'] ?? []);
            $due = array_values(array_unique(array_filter(array_map('strval', array_merge((array) ($req['currently_due'] ?? []), (array) ($req['past_due'] ?? []), (array) ($req['eventually_due'] ?? []))))));
            $disabled = is_string($req['disabled_reason'] ?? null) ? substr($req['disabled_reason'], 0, 80) : null;
            $hadIssue = $account->disabled_reason !== null;
            $account->forceFill([
                'details_submitted' => (bool) ($s['details_submitted'] ?? false), 'charges_enabled' => (bool) ($s['charges_enabled'] ?? false), 'payouts_enabled' => (bool) ($s['payouts_enabled'] ?? false),
                'requirements_due' => array_slice($due, 0, 50), 'disabled_reason' => $disabled, 'last_synced_at' => now(),
            ])->save();
            if ($disabled !== null && ! $hadIssue) {
                $this->notify->admins('stripe_accounts.view', 'stripe_account.issue', ['stripeAccountRecordId' => $account->id, 'expertId' => $account->expert_id]);
            }
        });
    }
}
