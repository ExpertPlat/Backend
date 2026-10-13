<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminPermission;
use App\Enums\ExpertKycApplicationStatus;
use App\Exceptions\InvalidKycTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Kyc\ApproveKycApplicationRequest;
use App\Http\Requests\Admin\Kyc\KycDecisionRequest;
use App\Http\Requests\Admin\Kyc\ListKycApplicationsRequest;
use App\Http\Requests\Admin\Kyc\RequestKycInformationRequest;
use App\Http\Requests\Admin\Kyc\ReviewKycDocumentRequest;
use App\Http\Resources\Admin\Kyc\KycApplicationDetailResource;
use App\Http\Resources\Admin\Kyc\KycApplicationSummaryResource;
use App\Http\Resources\Expert\Kyc\KycDocumentResource;
use App\Models\Admin;
use App\Models\ExpertKycApplication;
use App\Models\ExpertKycDocument;
use App\Services\Kyc\ExpertKycDocumentStorage;
use App\Services\Kyc\ExpertKycWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycController extends Controller
{
    public function __construct(
        private readonly ExpertKycWorkflow $workflow,
        private readonly ExpertKycDocumentStorage $documents,
    ) {}

    public function index(ListKycApplicationsRequest $request): JsonResponse
    {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        $validated = $request->validated();
        $sortColumns = [
            'submittedAt' => 'submitted_at',
            'updatedAt' => 'updated_at',
            'reference' => 'reference',
        ];

        $query = ExpertKycApplication::query()
            ->select([
                'id', 'reference', 'expert_id', 'attempt_number', 'status', 'domain',
                'jurisdiction', 'submitted_at', 'updated_at',
            ])
            ->with('expert:id,name,email')
            ->withCount('documents')
            ->whereIn('status', ExpertKycApplicationStatus::adminVisibleValues())
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['dateFrom'] ?? null, fn ($query, $date) => $query->whereDate('submitted_at', '>=', $date))
            ->when($validated['dateTo'] ?? null, fn ($query, $date) => $query->whereDate('submitted_at', '<=', $date))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $query->where('reference', 'like', $like)
                        ->orWhere('domain', 'like', $like)
                        ->orWhere('jurisdiction', 'like', $like)
                        ->orWhereHas('expert', fn ($expertQuery) => $expertQuery
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like));
                });
            });

        $sortBy = $sortColumns[$validated['sortBy'] ?? 'submittedAt'];
        $paginator = $query
            ->orderBy($sortBy, $validated['sortDirection'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($validated['perPage'] ?? 20)
            ->withQueryString();

        return response()->json([
            'status' => true,
            'message' => 'KYC applications retrieved successfully.',
            'data' => [
                'applications' => KycApplicationSummaryResource::collection($paginator->items()),
                'pagination' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(ExpertKycApplication $application): JsonResponse
    {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);
        $this->ensureVisible($application);

        return $this->applicationResponse(
            $this->workflow->loadApplication($application, true),
            'KYC application retrieved successfully.',
        );
    }

    public function startReview(Request $request, ExpertKycApplication $application): JsonResponse
    {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        /** @var Admin $admin */
        $admin = $request->user();

        return $this->applicationResponse(
            $this->workflow->startReview($application, $admin),
            'KYC review started successfully.',
        );
    }

    public function reviewDocument(
        ReviewKycDocumentRequest $request,
        ExpertKycApplication $application,
        ExpertKycDocument $document,
    ): JsonResponse {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);
        abort_unless($document->application_id === $application->getKey(), 404);

        if ($application->status !== ExpertKycApplicationStatus::UnderReview) {
            throw new InvalidKycTransitionException('Documents can only be reviewed while the application is under review.');
        }

        /** @var Admin $admin */
        $admin = $request->user();
        $document = $this->documents->setReviewed($document, $admin, $request->boolean('reviewed'));

        return response()->json([
            'status' => true,
            'message' => 'Document review state updated successfully.',
            'data' => ['document' => new KycDocumentResource($document)],
        ]);
    }

    public function download(
        ExpertKycApplication $application,
        ExpertKycDocument $document,
    ): StreamedResponse {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);
        $this->ensureVisible($application);
        abort_unless($document->application_id === $application->getKey(), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->original_name,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function approve(
        ApproveKycApplicationRequest $request,
        ExpertKycApplication $application,
    ): JsonResponse {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        /** @var Admin $admin */
        $admin = $request->user();

        return $this->applicationResponse(
            $this->workflow->approve($application, $admin, $request->validated('scopes')),
            'KYC application approved successfully.',
        );
    }

    public function reject(KycDecisionRequest $request, ExpertKycApplication $application): JsonResponse
    {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        /** @var Admin $admin */
        $admin = $request->user();

        return $this->applicationResponse(
            $this->workflow->reject($application, $admin, $request->validated('reason')),
            'KYC application rejected successfully.',
        );
    }

    public function requestInformation(
        RequestKycInformationRequest $request,
        ExpertKycApplication $application,
    ): JsonResponse {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        /** @var Admin $admin */
        $admin = $request->user();

        return $this->applicationResponse(
            $this->workflow->requestInformation(
                $application,
                $admin,
                $request->validated('reason'),
                $request->validated('requestedChanges'),
            ),
            'Additional information requested successfully.',
        );
    }

    private function ensureVisible(ExpertKycApplication $application): void
    {
        abort_unless($application->status->isVisibleToAdmin(), 404);
    }

    private function applicationResponse(ExpertKycApplication $application, string $message): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => ['application' => new KycApplicationDetailResource($application)],
        ]);
    }
}
