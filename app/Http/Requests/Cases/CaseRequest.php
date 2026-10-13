<?php

namespace App\Http\Requests\Cases;

use App\Http\Requests\User\Privacy\PrivacyRequest;
use App\Models\CaseRecord;
use Illuminate\Support\Facades\Gate;

class CaseRequest extends PrivacyRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        if ($this->route('case')) {
            $case = $this->user()->cases()->findOrFail($this->route('case'));
            Gate::authorize($this->isMethod('GET') ? 'view' : 'update', $case);
            if ($this->route('document')) {
                $document = $case->documents()->findOrFail($this->route('document'));
                if ($this->route('version')) {
                    $document->versions()->findOrFail($this->route('version'));
                }
            }
            if ($this->route('snapshot')) {
                $case->snapshots()->findOrFail($this->route('snapshot'));
            }
        } else {
            Gate::authorize($this->isMethod('GET') ? 'viewAny' : 'create', CaseRecord::class);
        }

        return true;
    }
}
