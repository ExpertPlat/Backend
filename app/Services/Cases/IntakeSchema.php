<?php

namespace App\Services\Cases;

use App\Services\Catalog\CatalogTaxonomy;
use Illuminate\Validation\Rule;

class IntakeSchema
{
    public const FIELDS = ['schemaVersion', 'title', 'problemDescription', 'desiredOutcome', 'primaryDomain', 'domains', 'serviceNeeds', 'jurisdiction', 'language', 'urgency', 'privacyChoice', 'subjectType', 'answers', 'lastCompletedStep', 'followUpAnswers', 'residence'];

    public const REQUIRED = ['title', 'problemDescription', 'desiredOutcome', 'primaryDomain', 'domains', 'serviceNeeds', 'jurisdiction', 'language', 'urgency', 'privacyChoice', 'subjectType'];

    public const RISK_KEYS = ['immediateDanger', 'requiresInPerson', 'ambiguousHighRisk'];

    public function domains(): array
    {
        return CatalogTaxonomy::domains();
    }

    public function rules(): array
    {
        return [
            'schemaVersion' => ['sometimes', 'integer', Rule::in([1])],
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:160'],
            'problemDescription' => ['sometimes', 'required', 'string', 'min:1', 'max:10000'],
            'desiredOutcome' => ['sometimes', 'required', 'string', 'min:1', 'max:4000'],
            'primaryDomain' => ['sometimes', 'required', Rule::in($this->domains())],
            'domains' => ['sometimes', 'array', 'min:1', 'max:5'],
            'domains.*' => ['required', 'string', 'distinct:strict', Rule::in($this->domains())],
            'serviceNeeds' => ['sometimes', 'array', 'min:1', 'max:5'],
            'serviceNeeds.*' => ['required', 'string', 'distinct:strict', Rule::in(CatalogTaxonomy::deliveryModes())],
            'jurisdiction' => ['sometimes', 'required', Rule::in(config('countries'))],
            'language' => ['sometimes', 'required', Rule::in(config('case_intake.languages'))],
            'urgency' => ['sometimes', 'required', Rule::in(['normal', 'soon', 'urgent'])],
            'privacyChoice' => ['sometimes', 'required', Rule::in(['private'])],
            'subjectType' => ['sometimes', 'required', Rule::in(['self'])],
            'answers' => ['sometimes', 'array:'.implode(',', self::RISK_KEYS)],
            'answers.immediateDanger' => ['sometimes', 'required', 'boolean'],
            'answers.requiresInPerson' => ['sometimes', 'required', 'boolean'],
            'answers.ambiguousHighRisk' => ['sometimes', 'required', 'boolean'],
            'lastCompletedStep' => ['sometimes', 'required', Rule::in(['description', 'context', 'details', 'documents', 'review'])],
        ];
    }

    public function bootstrap(): array
    {
        return ['contractVersion' => config('case_intake.contract_version'), 'schemaVersion' => 1, 'rulesVersion' => config('case_intake.rules_version'),
            'domains' => collect($this->domains())->map(fn ($d) => ['key' => $d, 'enabled' => in_array($d, CatalogTaxonomy::enabledDomains(), true) && ! in_array($d, CatalogTaxonomy::domains(true), true)])->all(),
            'jurisdictions' => config('countries'), 'enabledJurisdictions' => array_values(array_intersect(CatalogTaxonomy::enabledCountries(), config('countries'))),
            'languages' => config('case_intake.languages'), 'serviceNeeds' => CatalogTaxonomy::deliveryModes(), 'urgencies' => ['normal', 'soon', 'urgent'],
            'fields' => ['title' => ['type' => 'string', 'maxLength' => 160], 'problemDescription' => ['type' => 'string', 'maxLength' => 10000], 'desiredOutcome' => ['type' => 'string', 'maxLength' => 4000]],
            'requiredForSubmit' => self::REQUIRED, 'questions' => array_map(fn ($key) => ['key' => $key, 'type' => 'boolean', 'requiredForSubmit' => true], self::RISK_KEYS),
            'steps' => ['description', 'context', 'details', 'documents', 'review'], 'privacyChoices' => ['private'], 'subjectTypes' => ['self'],
            'limits' => ['maxDomains' => 5, 'maxDocuments' => config('case_intake.max_documents'), 'maxRevisions' => config('case_intake.max_revisions'), 'maxFileKiB' => config('case_intake.max_file_kib')],
            'documents' => ['allowedMimeTypes' => ['application/pdf', 'image/jpeg', 'image/png'], 'previewMimeTypes' => ['image/jpeg', 'image/png'], 'scanRequired' => true],
            'operational' => ['serviceConfigured' => CatalogTaxonomy::enabledCountries() !== [], 'thirdPartySubmissionEnabled' => false, 'humanTriageConfigured' => $this->triageUrl() !== null],
        ];
    }

    public function triageUrl(): ?string
    {
        $url = config('case_intake.triage_url');

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
    }
}
