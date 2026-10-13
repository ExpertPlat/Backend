<?php

namespace App\Console\Commands;

use App\Services\Payments\StripeGateway;
use App\Support\Money;
use Illuminate\Console\Command;

/** Secret-free configuration readiness report (DR-083). Live Mode fails until the legal-entity country is explicitly configured. */
class PaymentsReadiness extends Command
{
    protected $signature = 'payments:readiness {--json : Print machine-readable output}';

    protected $description = 'Check Stripe payment configuration readiness without exposing secrets';

    public function handle(): int
    {
        $mode = StripeGateway::mode();
        $country = (string) config('payments.stripe.platform_country');
        $checks = [];
        $add = function (string $code, bool $ok, string $level = 'error') use (&$checks): void {
            $checks[] = ['code' => $code, 'ok' => $ok, 'level' => $level];
        };

        $add('STRIPE_SECRET_KEY_CONFIGURED', $mode !== 'unconfigured');
        $add('STRIPE_WEBHOOK_SECRET_CONFIGURED', (string) config('payments.stripe.webhook_secret') !== '');
        $add('STRIPE_PUBLISHABLE_KEY_CONFIGURED', (string) config('payments.stripe.publishable') !== '', 'warning');
        foreach (['connect_return', 'connect_refresh', 'checkout_success', 'checkout_cancel'] as $key) {
            $add('URL_'.strtoupper($key).'_CONFIGURED', (string) config('payments.urls.'.$key) !== '');
        }
        $publishable = (string) config('payments.stripe.publishable');
        $add('KEYS_MODE_CONSISTENT', $publishable === '' || $mode === 'unconfigured' || str_starts_with($publishable, $mode === 'live' ? 'pk_live_' : 'pk_test_'));
        $add('COMMISSION_BPS_VALID', config('payments.commission_bps') >= 0 && config('payments.commission_bps') <= 10000);
        $add('CURRENCIES_VALID', Money::allowed() === array_values((array) config('commerce.currencies')) && Money::allowed() !== []);
        $countryValid = preg_match('/^[A-Z]{2}$/D', $country) === 1;
        // DR-083: the legal entity country is deliberately unresolved. Test Mode may run without it; Live Mode may not.
        $add('PRODUCTION_COUNTRY_NOT_CONFIGURED', $countryValid, $mode === 'live' ? 'error' : 'warning');
        $add('QUEUE_NOT_SYNC_IN_LIVE', $mode !== 'live' || config('queue.default') !== 'sync');
        $add('REVIEWS_WRITE_STILL_DISABLED', ! config('reviews.write_enabled'), 'warning');

        $failed = array_values(array_filter($checks, fn (array $c): bool => ! $c['ok'] && $c['level'] === 'error'));
        $warnings = array_values(array_filter($checks, fn (array $c): bool => ! $c['ok'] && $c['level'] === 'warning'));
        $ready = $failed === [];
        $report = ['mode' => $mode, 'ready' => $ready, 'productionReady' => false,
            'failed' => array_column($failed, 'code'), 'warnings' => array_column($warnings, 'code'),
            'note' => 'Sprint 5 is Test Mode only; production activation requires the legal-entity country decision and a verified Stripe capability matrix.'];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Stripe mode: '.$mode);
            foreach ($checks as $c) {
                $this->line(sprintf('  [%s] %s', $c['ok'] ? ' ok ' : ($c['level'] === 'error' ? 'FAIL' : 'warn'), $c['code']));
            }
            $this->line($ready ? 'Test/configuration readiness: PASS (production readiness is never claimed in Sprint 5).' : 'Readiness: FAIL');
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
