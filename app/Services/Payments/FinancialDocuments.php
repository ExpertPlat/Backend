<?php

namespace App\Services\Payments;

use App\Models\FinancialDocument;
use App\Models\Funding;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Invoice (amount due) and receipt (amount paid) records. One of each per Funding (UNIQUE), so retries never duplicate them.
 * They are NOT tax invoices (DR-088) and carry no tax calculation.
 */
class FinancialDocuments
{
    public function invoice(Funding $funding): FinancialDocument
    {
        return $this->once($funding, FinancialDocument::INVOICE, 'INV', 'issued', $funding->gross_amount_minor, null);
    }

    /** Issues the receipt for a confirmed payment and marks the invoice paid. */
    public function receipt(Funding $funding, ?string $providerReceiptUrl): FinancialDocument
    {
        $receipt = $this->once($funding, FinancialDocument::RECEIPT, 'RCT', 'paid', $funding->gross_amount_minor, $providerReceiptUrl);
        FinancialDocument::where(['funding_id' => $funding->id, 'document_type' => FinancialDocument::INVOICE])->where('status', 'issued')->update(['status' => 'paid', 'updated_at' => now()]);

        return $receipt;
    }

    private function once(Funding $funding, string $type, string $prefix, string $status, int $amount, ?string $url): FinancialDocument
    {
        $existing = FinancialDocument::where(['funding_id' => $funding->id, 'document_type' => $type])->first();
        if ($existing !== null) {
            return $existing;
        }
        $doc = new FinancialDocument;
        $doc->forceFill(['funding_id' => $funding->id, 'user_id' => $funding->user_id, 'document_type' => $type, 'number' => sprintf('%s-%s-%08d', $prefix, now()->format('Y'), $funding->id),
            'amount_minor' => $amount, 'currency' => $funding->currency, 'status' => $status, 'issued_at' => now(), 'provider_receipt_url' => $url]);
        try {
            $doc->save();
        } catch (UniqueConstraintViolationException) {
            return FinancialDocument::where(['funding_id' => $funding->id, 'document_type' => $type])->firstOrFail();
        }

        return $doc;
    }
}
