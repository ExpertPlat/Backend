<?php

namespace App\Services\Catalog;

use App\Enums\JurisdictionMode;
use App\Models\CatalogNode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogPolicy
{
    public function validate(array $p): array
    {
        $rules = [
            'domain' => ['required', 'string', 'max:100'], 'specialty' => ['required', 'string', 'max:100'], 'serviceType' => ['required', 'string', 'max:100'],
            'deliveryModes' => ['required', 'array', 'min:1', 'max:10'], 'deliveryModes.*' => ['required', 'string', 'distinct', 'max:100'],
            'jurisdictionMode' => ['required', Rule::enum(JurisdictionMode::class)], 'jurisdictionCodes' => ['present', 'array', 'max:20'], 'jurisdictionCodes.*' => ['required', 'string', 'distinct', 'max:100'],
            'regulated' => ['required', 'boolean'], 'remoteDeliveryAllowed' => ['required', 'boolean'], 'requiresVerifiedScope' => ['required', 'accepted'],
            'requiresProfessionalLicense' => ['required', 'boolean'], 'requiredEvidenceType' => ['required', Rule::in(['credential', 'qualification', 'experience'])],
            'minimumExperts' => ['required', 'integer', 'min:1', 'max:20'], 'maximumExperts' => ['required', 'integer', 'gte:minimumExperts', 'max:20'],
            'commerciallyAvailable' => ['required', 'boolean'], 'operationallyAvailable' => ['required', 'boolean'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d\TH:i:s\Z'], 'effectiveUntil' => ['nullable', 'date_format:Y-m-d\TH:i:s\Z', 'after:effectiveFrom'],
            'requiredConsents' => ['required', 'array', 'min:2', 'max:2'], 'requiredConsents.*' => ['required', 'distinct', Rule::in(['terms', 'privacy'])],
            'requiredDocuments' => ['present', 'array', 'max:20'], 'requiredDocuments.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/D', 'distinct'],
            'intakeSchema' => ['present', 'array', 'max:50'], 'intakeSchema.*' => ['array:key,type,required,label,options'],
            'intakeSchema.*.key' => ['required', 'string', 'regex:/^[a-z][a-zA-Z0-9_]{0,63}$/D', 'distinct'],
            'intakeSchema.*.type' => ['required', Rule::in(['text', 'boolean', 'number', 'choice'])],
            'intakeSchema.*.required' => ['required', 'boolean'], 'intakeSchema.*.label' => ['required', 'array:ar,en'],
            'intakeSchema.*.label.ar' => ['required', 'string', 'max:200'], 'intakeSchema.*.label.en' => ['required', 'string', 'max:200'],
            'intakeSchema.*.options' => ['sometimes', 'array', 'min:1', 'max:50'], 'intakeSchema.*.options.*' => ['required', 'string', 'max:100'],
            'matchingRules' => ['required', 'array:keywords,prohibitedKeywords,humanReviewRequired'],
            'matchingRules.keywords' => ['present', 'array', 'max:50'], 'matchingRules.keywords.*' => ['string', 'min:2', 'max:80'],
            'matchingRules.prohibitedKeywords' => ['present', 'array', 'max:50'], 'matchingRules.prohibitedKeywords.*' => ['string', 'min:2', 'max:80'],
            'matchingRules.humanReviewRequired' => ['required', 'boolean'],
        ];
        $v = Validator::make($p, $rules);
        $v->after(function ($v) use ($p, $rules) {
            foreach (array_diff(array_keys($p), array_keys($rules)) as $key) {
                $v->errors()->add($key, 'Unknown policy field.');
            }
        });
        $p = $v->validate();
        $domain = $this->node('domain', $p['domain']);
        $specialty = $this->node('specialty', $p['specialty']);
        if ($domain->regulated === null || ($domain->regulated && ! (bool) $p['regulated'])) {
            $this->fail('regulated', 'Domain classification is missing or conflicts with the policy.');
        }
        $service = $this->node('service', $p['serviceType']);
        if ($specialty->parent_id !== $domain->id || $service->parent_id !== $specialty->id) {
            $this->fail('serviceType', 'Catalog hierarchy does not match.');
        }
        foreach ($p['deliveryModes'] as $code) {
            $this->node('delivery_mode', $code);
        }
        foreach ($p['jurisdictionCodes'] as $code) {
            $this->node('jurisdiction', $code);
        }
        $n = count($p['jurisdictionCodes']);
        if (($p['jurisdictionMode'] === 'GLOBAL' && $n !== 0) || ($p['jurisdictionMode'] === 'COUNTRY_SPECIFIC' && $n !== 1) || ($p['jurisdictionMode'] === 'MULTI_COUNTRY' && $n < 2)) {
            $this->fail('jurisdictionCodes', 'Jurisdiction coverage does not match mode.');
        }
        if ($p['regulated'] && ($p['jurisdictionMode'] === 'GLOBAL' || ! $p['requiresProfessionalLicense'] || $p['requiredEvidenceType'] !== 'credential')) {
            $this->fail('regulated', 'Regulated services require jurisdiction-specific professional license evidence.');
        }
        foreach ($p['intakeSchema'] as $field) {
            if ($field['type'] === 'choice' && (empty($field['options']) || count($field['options']) !== count(array_unique($field['options'])))) {
                $this->fail('intakeSchema', 'Choice fields require distinct options.');
            }
        }

        return $p;
    }

    public function node(string $kind, string $code): CatalogNode
    {
        $node = CatalogNode::where('kind', $kind)->where('code', $code)->first();
        if (! $node) {
            $this->fail($kind, 'Unknown catalog code.');
        }

        return $node;
    }

    public function answers(array $schema, array $answers, bool $complete = false): array
    {
        $rules = [];
        foreach ($schema as $f) {
            $r = [$complete && $f['required'] ? 'required' : 'sometimes'];
            $r = array_merge($r, match ($f['type']) {
                'boolean' => ['boolean'],'number' => ['numeric', 'between:-1000000000,1000000000'],'choice' => ['string', Rule::in($f['options'])],default => ['string', 'max:4000']
            });
            $rules[$f['key']] = $r;
        }
        $v = Validator::make($answers, $rules);
        $v->after(function ($v) use ($answers, $rules) {
            foreach (array_diff(array_keys($answers), array_keys($rules)) as $key) {
                $v->errors()->add($key, 'Unknown answer field.');
            }
        });

        return $v->validate();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
