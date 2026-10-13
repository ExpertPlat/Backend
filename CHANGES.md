# Sprint 5 changes: Payments, Funding, Stripe Connect, Ledger, Refunds, Reconciliation (Test Mode)

Gate G5: CONDITIONAL PASS (local). Details: `docs/sprint5/VERIFICATION.md`. File list: `CHANGED_FILES.md`.

## Summary
Stripe Connect (Separate Charges and Transfers, charge side only), hosted Checkout and Connect onboarding, Funding per Agreement, append-only ledger, refund foundation, disputes persistence, receipts/invoices, reconciliation, Admin finance endpoints. No Transfer or Payout exists. Test Mode only; Live Mode is blocked until the legal-entity country is chosen (DR-083). Decisions DR-083..DR-090 are in `CLAUDE.md` section 16B.

## Commands
```
composer install
php artisan migrate
php artisan db:seed --class=FinancePermissionsSeeder
php artisan payments:readiness
php artisan finance:expire      # scheduled every minute
php artisan finance:reconcile   # scheduled every 10 minutes
vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## Migration
`2026_10_03_200000_create_payments_tables` (additive): `stripe_accounts, fundings, funding_attempts, financial_ledger_events, stripe_webhook_events, funding_refunds, funding_disputes, financial_documents, reconciliation_issues` plus nullable `bookings.free_reschedule_granted_at`. Rolling back deletes financial history; see `docs/sprint5/ROLLBACK.md`.

## Environment keys (names only, no values)
`STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY, STRIPE_WEBHOOK_SECRET, STRIPE_PLATFORM_COUNTRY (leave unset), STRIPE_CONNECT_RETURN_URL, STRIPE_CONNECT_REFRESH_URL, STRIPE_CHECKOUT_SUCCESS_URL, STRIPE_CHECKOUT_CANCEL_URL, PAYMENTS_COMMISSION_BPS, PAYMENTS_WEBHOOK_TOLERANCE_SECONDS, PAYMENTS_RECONCILE_STALE_MINUTES, COMMERCE_FUNDING_WINDOW_HOURS, COMMERCE_FUNDING_MIN_LEAD_MINUTES, COMMERCE_FUNDING_IMMEDIATE_WINDOW_MINUTES`. `COMMERCE_PLATFORM_COMMISSION_PERCENT` is replaced by `PAYMENTS_COMMISSION_BPS`.

## Deployment
See `docs/sprint5/DEPLOYMENT.md`. Scheduler and queue worker are required; create the Stripe test webhook endpoint `/api/webhooks/stripe`.

## Tests
Full suite 545 passed, 22207 assertions; Sprint 5 suite 110 tests. MySQL, real Stripe, Staging, real mail and React were not verified.

## Rollback
Redeploy the previous release; do not roll the schema back once real payments exist. See `docs/sprint5/ROLLBACK.md`.
