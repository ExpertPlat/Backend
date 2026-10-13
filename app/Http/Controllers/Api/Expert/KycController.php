<?php

namespace App\Http\Controllers\Api\Expert;

use App\Enums\ExpertKycDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Kyc\SaveKycApplicationRequest;
use App\Http\Requests\Expert\Kyc\UploadKycDocumentRequest;
use App\Http\Resources\Expert\Kyc\KycApplicationResource;
use App\Http\Resources\Expert\Kyc\KycDocumentResource;
use App\Models\Expert;
use App\Models\ExpertKycDocument;
use App\Services\Kyc\ExpertKycDocumentStorage;
use App\Services\Kyc\ExpertKycWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycController extends Controller
{
    public function __construct(
        private readonly ExpertKycWorkflow $workflow,
        private readonly ExpertKycDocumentStorage $documents,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $application = $expert->kycApplications()->latest('attempt_number')->first();

        return response()->json([
            'status' => true,
            'message' => 'KYC information retrieved successfully.',
            'data' => [
                'kycStatus' => $expert->kyc_status->value,
                'prefill' => $this->workflow->prefill($expert),
                'application' => $application
                    ? new KycApplicationResource($this->workflow->loadApplication($application))
                    : null,
            ],
        ]);
    }

    public function update(SaveKycApplicationRequest $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $application = $this->workflow->saveDraft($expert, $request->validated());

        return response()->json([
            'status' => true,
            'message' => 'KYC draft saved successfully.',
            'data' => ['application' => new KycApplicationResource($application)],
        ]);
    }

    public function upload(UploadKycDocumentRequest $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $application = $expert->kycApplications()->latest('attempt_number')->first();

        if (! $application) {
            return response()->json([
                'status' => false,
                'message' => 'Save the KYC draft before uploading documents.',
            ], 409);
        }

        $validated = $request->validated();
        $document = $this->documents->store(
            $application,
            ExpertKycDocumentType::from($validated['documentType']),
            $validated['file'],
            $validated['qualificationId'] ?? null,
            $validated['credentialId'] ?? null,
        );

        return response()->json([
            'status' => true,
            'message' => 'KYC document uploaded successfully.',
            'data' => ['document' => new KycDocumentResource($document)],
        ], 201);
    }

    public function destroy(Request $request, ExpertKycDocument $document): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $document->load('application');
        abort_unless($document->application->expert_id === $expert->getKey(), 404);

        $this->documents->delete($document);

        return response()->json([
            'status' => true,
            'message' => 'KYC document deleted successfully.',
            'data' => null,
        ]);
    }

    public function download(Request $request, ExpertKycDocument $document): StreamedResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $document->load('application');
        abort_unless($document->application->expert_id === $expert->getKey(), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->original_name,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function submit(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $application = $this->workflow->submit($expert);

        return response()->json([
            'status' => true,
            'message' => 'KYC application submitted for review successfully.',
            'data' => ['application' => new KycApplicationResource($application)],
        ]);
    }
}
