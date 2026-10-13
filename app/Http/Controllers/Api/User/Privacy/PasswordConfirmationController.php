<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\ConfirmPasswordRequest;
use App\Services\Privacy\AuditWriter;
use App\Services\Privacy\PasswordConfirmation;
use Illuminate\Http\JsonResponse;

class PasswordConfirmationController extends Controller
{
    use PrivacyResponses;

    public function store(ConfirmPasswordRequest $request, PasswordConfirmation $confirmation, AuditWriter $audit): JsonResponse
    {
        $until = $confirmation->confirm($request->user(), $request->validated('currentPassword'), $request->validated('purpose'));
        $audit->write($request->user(), 'user.privacy_identity_confirmed', 'user', $request->user()->id);

        return $this->item(['purpose' => $request->validated('purpose'), 'confirmedUntil' => $until->toISOString()]);
    }
}
