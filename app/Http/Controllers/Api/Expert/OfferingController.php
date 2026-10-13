<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\ExpertMatchingRequest;
use App\Http\Resources\Matching\MatchingPresenter;
use App\Models\ExpertOffering;
use App\Services\Matching\ExpertOfferings;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

class OfferingController extends Controller
{
    use MatchingResponses;

    public function eligibleServices(ExpertMatchingRequest $r, ExpertOfferings $offerings): JsonResponse
    {
        return $this->items(collect($offerings->eligibleServices($r->expert()))->map(function ($s, $entryId) {
            $p = $s['policy'];

            return ['catalogEntryId' => $entryId, 'entryCode' => $s['entry']->code, 'service' => ['domain' => $p['domain'], 'specialty' => $p['specialty'], 'serviceType' => $p['serviceType']],
                'deliveryModes' => $s['deliveryModes'], 'languages' => $s['languages'], 'jurisdictionCodes' => $s['jurisdictionCodes']];
        })->values()->all());
    }

    public function index(ExpertMatchingRequest $r, ExpertOfferings $offerings): JsonResponse
    {
        $services = $offerings->eligibleServices($r->expert());
        $q = $r->expert()->offerings()->with('entry.versions')->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q, $r->validated(), fn ($o) => MatchingPresenter::offering($o, $offerings->eligibility($r->expert(), $o, $services)));
    }

    public function show(ExpertMatchingRequest $r, int $offering, ExpertOfferings $offerings): JsonResponse
    {
        $o = $r->expert()->offerings()->findOrFail($offering);

        return $this->item(MatchingPresenter::offering($o, $offerings->eligibility($r->expert(), $o)));
    }

    public function store(ExpertMatchingRequest $r, ExpertOfferings $offerings, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), function () use ($r, $offerings) {
            $o = $offerings->create($r->expert(), $r->validated());

            return $this->item(MatchingPresenter::offering($o, $offerings->eligibility($r->expert(), $o)), 'Offering created as a draft.', 201);
        });
    }

    public function update(ExpertMatchingRequest $r, int $offering, ExpertOfferings $offerings, ActorIdempotency $idempotency): JsonResponse
    {
        return $this->write($r, $idempotency, $offerings, fn () => $offerings->update($r->expert(), $offering, $r->validated()), 'Offering updated.');
    }

    public function publish(ExpertMatchingRequest $r, int $offering, ExpertOfferings $offerings, ActorIdempotency $idempotency): JsonResponse
    {
        return $this->write($r, $idempotency, $offerings, fn () => $offerings->publish($r->expert(), $offering, $r->validated()), 'Offering published.');
    }

    public function pause(ExpertMatchingRequest $r, int $offering, ExpertOfferings $offerings, ActorIdempotency $idempotency): JsonResponse
    {
        return $this->write($r, $idempotency, $offerings, fn () => $offerings->pause($r->expert(), $offering, $r->validated()), 'Offering paused.');
    }

    private function write(ExpertMatchingRequest $r, ActorIdempotency $idempotency, ExpertOfferings $offerings, \Closure $action, string $message): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), function () use ($r, $offerings, $action, $message) {
            /** @var ExpertOffering $o */
            $o = $action();

            return $this->item(MatchingPresenter::offering($o, $offerings->eligibility($r->expert(), $o)), $message);
        });
    }
}
