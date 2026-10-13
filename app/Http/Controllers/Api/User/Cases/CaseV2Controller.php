<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cases\CaseV2Request;
use App\Http\Resources\Cases\CaseV2Resource;
use App\Services\Catalog\CaseReadiness;
use App\Services\Catalog\CaseV2Workflow;
use App\Services\Catalog\CatalogMutex;
use App\Services\Privacy\Idempotency;
use Illuminate\Support\Facades\DB;

class CaseV2Controller extends Controller
{
    use PrivacyResponses;

    public function index(CaseV2Request $r)
    {
        $q = $r->owner()->cases()->getQuery()->with('currentIntake');
        if ($r->filled('status')) {
            $q->where('readiness_status', $r->input('status'));
        }

        return $this->listing($q, $r->validated(), CaseV2Resource::class, ['updatedAt' => 'updated_at', 'createdAt' => 'created_at']);
    }

    public function show(CaseV2Request $r, int $case)
    {
        return $this->item(new CaseV2Resource($r->owner()->cases()->with(['currentIntake', 'serviceScopes.catalogVersion'])->findOrFail($case)));
    }

    public function readiness(CaseV2Request $r, int $case, CaseReadiness $engine)
    {
        return $this->item($engine->assess($r->owner()->cases()->findOrFail($case)));
    }

    public function write(CaseV2Request $r, CaseV2Workflow $workflow, Idempotency $i)
    {
        return DB::transaction(function () use ($r, $workflow, $i) {
            CatalogMutex::lock();

            return $i->run($r, $r->validated(), function () use ($r, $workflow) {
                $action = last(explode('.', $r->route()->getName()));
                $d = $r->validated();
                $c = $action === 'store' ? $workflow->create($r->owner(), $d) : $workflow->{match ($action) {
                    'assessment' => 'assess',default => $action
                }}($r->owner(), (int) $r->route('case'), $d);

                return $this->item(new CaseV2Resource($c->load(['currentIntake', 'serviceScopes.catalogVersion'])), 'Case updated.', $action === 'store' ? 201 : 200);
            });
        });
    }
}
