<?php

namespace App\Http\Controllers\Api\Expert\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Auth\LoginRequest;
use App\Http\Requests\Expert\Auth\RegisterRequest;
use App\Http\Resources\Expert\ExpertResource;
use App\Models\Expert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register an expert and issue a limited access token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$expert, $plainTextToken] = DB::transaction(function () use ($validated): array {
            $expert = Expert::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'country' => $validated['country'] ?? null,
                'language' => $validated['language'] ?? null,
                'domain' => $validated['domain'] ?? null,
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]);

            $expert->refresh();

            return [
                $expert,
                $expert->createToken(
                    $this->tokenName($validated),
                    [Expert::ACCESS_ABILITY],
                )->plainTextToken,
            ];
        });

        $expert->sendEmailVerificationNotification();

        return $this->authenticationResponse(
            $expert,
            $plainTextToken,
            'Expert account created successfully. Please verify your email address.',
            201,
        );
    }

    /**
     * Authenticate an expert and issue a limited access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $expert = Expert::query()
            ->select([
                'id',
                'name',
                'email',
                'email_verified_at',
                'country',
                'language',
                'domain',
                'password',
                'is_active',
                'kyc_status',
                'created_at',
                'updated_at',
            ])
            ->where('email', $validated['email'])
            ->first();

        if (! $expert || ! Hash::check($validated['password'], $expert->password) || ! $expert->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        $plainTextToken = $expert
            ->createToken($this->tokenName($validated), [Expert::ACCESS_ABILITY])
            ->plainTextToken;

        return $this->authenticationResponse(
            $expert,
            $plainTextToken,
            'Expert logged in successfully.',
        );
    }

    /**
     * Return the authenticated expert's authentication state.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();

        return response()->json([
            'status' => true,
            'message' => 'Expert retrieved successfully.',
            'data' => $this->expertState($expert),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'status' => true,
            'message' => 'Expert logged out successfully.',
            'data' => null,
        ]);
    }

    /**
     * Revoke all tokens owned by the authenticated expert.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Expert logged out from all devices successfully.',
            'data' => null,
        ]);
    }

    private function authenticationResponse(
        Expert $expert,
        string $plainTextToken,
        string $message,
        int $statusCode = 200,
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                ...$this->expertState($expert),
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], $statusCode);
    }

    /**
     * @return array{expert: ExpertResource, email_verified: bool, kyc_status: string}
     */
    private function expertState(Expert $expert): array
    {
        return [
            'expert' => new ExpertResource($expert),
            'email_verified' => $expert->hasVerifiedEmail(),
            'kyc_status' => $expert->kyc_status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function tokenName(array $validated): string
    {
        $deviceName = $validated['device_name'] ?? null;

        return is_string($deviceName) && $deviceName !== ''
            ? $deviceName
            : 'Expert API Token';
    }
}
