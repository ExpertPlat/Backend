<?php

namespace App\Services\Cases;

use App\Enums\CaseSuitability;
use App\Models\CatalogVersion;
use App\Services\Catalog\CatalogTaxonomy;

class DeterministicIntake
{
    public function assess(array $input): array
    {
        $questions = [];
        $reasons = [];
        $risk = $input['answers'] ?? [];
        foreach (IntakeSchema::REQUIRED as $field) {
            if (! isset($input[$field]) || $input[$field] === '' || $input[$field] === []) {
                $questions[] = ['field' => $field, 'reasonCode' => 'REQUIRED_INFORMATION'];
            }
        }
        foreach (IntakeSchema::RISK_KEYS as $key) {
            if (! array_key_exists($key, $risk)) {
                $questions[] = ['field' => 'answers.'.$key, 'reasonCode' => 'REQUIRED_RISK_ANSWER'];
            }
        }
        if (isset($input['primaryDomain'],$input['domains']) && ! in_array($input['primaryDomain'], $input['domains'], true)) {
            $questions[] = ['field' => 'primaryDomain', 'reasonCode' => 'PRIMARY_DOMAIN_MUST_BE_SELECTED'];
        }
        $text = mb_strtolower(($input['title'] ?? '').' '.($input['problemDescription'] ?? ''));
        $keywords = [];
        foreach (CatalogVersion::where('status', 'enabled')->get() as $v) {
            $keywords[$v->policy['domain']] = array_merge($keywords[$v->policy['domain']] ?? [], $v->policy['matchingRules']['keywords']);
        }
        $suggestions = [];
        foreach ($keywords as $domain => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $suggestions[] = ['domain' => $domain, 'origin' => 'rules', 'reasonCode' => 'KEYWORD_INDICATOR'];
                    break;
                }
            }
        }
        $urgent = (bool) ($risk['immediateDanger'] ?? false) || preg_match('/(?:suicid|kill myself|انتحار|نزيف شديد)/u', $text) === 1;
        $unsupported = false;
        if (isset($input['jurisdiction']) && ! in_array($input['jurisdiction'], CatalogTaxonomy::enabledCountries(), true)) {
            $unsupported = true;
            $reasons[] = 'JURISDICTION_NOT_ENABLED';
        }
        foreach (array_unique(array_merge($input['domains'] ?? [], isset($input['primaryDomain']) ? [$input['primaryDomain']] : [])) as $domain) {
            if (! in_array($domain, CatalogTaxonomy::enabledDomains(), true) || in_array($domain, CatalogTaxonomy::domains(true), true)) {
                $unsupported = true;
                $reasons[] = 'DOMAIN_NOT_ENABLED';
            }
        }
        if (array_diff($input['serviceNeeds'] ?? [], CatalogTaxonomy::deliveryModes())) {
            $unsupported = true;
            $reasons[] = 'SERVICE_NOT_ENABLED';
        }
        if ($risk['requiresInPerson'] ?? false) {
            $unsupported = true;
            $reasons[] = 'IN_PERSON_REQUIRED';
        }
        if (($input['subjectType'] ?? 'self') !== 'self') {
            $unsupported = true;
            $reasons[] = 'REPRESENTATION_REQUIRED';
        }
        $ambiguous = (bool) ($risk['ambiguousHighRisk'] ?? false);
        if ($urgent) {
            $outcome = CaseSuitability::UrgentStop;
            $reasons[] = 'IMMEDIATE_RISK_INDICATOR';
            $next = 'seek_local_emergency_assistance';
        } elseif ($unsupported) {
            $outcome = CaseSuitability::Unsupported;
            $next = 'review_supported_scope';
        } elseif ($ambiguous) {
            $outcome = CaseSuitability::NeedsClarification;
            $reasons[] = 'HUMAN_TRIAGE_REQUIRED';
            $next = app(IntakeSchema::class)->triageUrl() ? 'contact_human_triage' : 'stop_contact_support';
        } elseif ($questions) {
            $outcome = CaseSuitability::NeedsClarification;
            $reasons[] = 'INCOMPLETE_INTAKE';
            $next = 'complete_information';
        } else {
            $outcome = CaseSuitability::Suitable;
            $next = 'confirm_intake';
        }

        return ['origin' => 'rules', 'suitability' => $outcome->value, 'reasonCodes' => array_values(array_unique($reasons)), 'clarifications' => $questions, 'suggestions' => $suggestions, 'nextAction' => $next, 'triageUrl' => $ambiguous ? app(IntakeSchema::class)->triageUrl() : null];
    }
}
