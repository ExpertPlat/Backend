<?php

require __DIR__.'/catalog.php';
require __DIR__.'/cases_v2.php';

use App\Http\Controllers\Api\Admin\AdminExpertController;
use App\Http\Controllers\Api\Admin\AdminManagementController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\AuthorizationController as AdminAuthorizationController;
use App\Http\Controllers\Api\Admin\KycController as AdminKycController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Expert\Auth\AuthController as ExpertAuthController;
use App\Http\Controllers\Api\Expert\Auth\EmailVerificationController as ExpertEmailVerificationController;
use App\Http\Controllers\Api\Expert\Auth\PasswordController as ExpertPasswordController;
use App\Http\Controllers\Api\Expert\KycController as ExpertKycController;
use App\Http\Controllers\Api\Expert\Profile\AvailabilityController as ExpertAvailabilityController;
use App\Http\Controllers\Api\Expert\Profile\ProfessionalProfileController as ExpertProfileController;
use App\Http\Controllers\Api\PublicApi\ExpertProfileController as PublicExpertProfileController;
use App\Http\Controllers\Api\User\Auth\EmailVerificationController as UserEmailVerificationController;
use App\Http\Controllers\Api\User\Auth\PasswordController as UserPasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
});

Route::prefix('admin/kyc')->name('admin.kyc.')
    ->middleware([
        'auth:sanctum',
        'admin',
        'abilities:admin:access',
        'can:experts.reviewKyc',
    ])
    ->group(function (): void {
        Route::get('/applications', [AdminKycController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [AdminKycController::class, 'show'])->name('applications.show');
        Route::post('/applications/{application}/start-review', [AdminKycController::class, 'startReview'])
            ->middleware('throttle:admin-kyc-decisions')
            ->name('applications.start-review');
        Route::put('/applications/{application}/documents/{document}/review', [AdminKycController::class, 'reviewDocument'])
            ->middleware('throttle:admin-kyc-decisions')
            ->name('documents.review');
        Route::get('/applications/{application}/documents/{document}', [AdminKycController::class, 'download'])
            ->name('documents.show');
        Route::post('/applications/{application}/approve', [AdminKycController::class, 'approve'])
            ->middleware('throttle:admin-kyc-decisions')
            ->name('applications.approve');
        Route::post('/applications/{application}/reject', [AdminKycController::class, 'reject'])
            ->middleware('throttle:admin-kyc-decisions')
            ->name('applications.reject');
        Route::post('/applications/{application}/request-information', [AdminKycController::class, 'requestInformation'])
            ->middleware('throttle:admin-kyc-decisions')
            ->name('applications.request-information');
    });

Route::post('/forgot-password', [UserPasswordController::class, 'forgot'])
    ->middleware('throttle:user-password-forgot')
    ->name('auth.password.forgot');
Route::post('/reset-password', [UserPasswordController::class, 'reset'])
    ->middleware('throttle:user-password-reset')
    ->name('auth.password.reset');
Route::get('/email/verify/{id}/{hash}', [UserEmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('auth.email.verify');

Route::middleware(['auth:sanctum', 'regular-user'])->group(function (): void {
    Route::post('/email/verification-notification', [UserEmailVerificationController::class, 'send'])
        ->middleware('throttle:user-verification')
        ->name('auth.email.send');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
});

Route::middleware(['auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'])
    ->group(function (): void {
        Route::get('/user', [\App\Http\Controllers\Api\Front\UserAccountController::class, 'me'])->name('auth.user');
    });

Route::get('/media/expert-avatars/{profile}/{file}', [\App\Http\Controllers\Api\PublicApi\ExpertAvatarController::class, 'show'])
    ->whereNumber('profile')->where('file', '[A-Za-z0-9._-]+')->middleware('throttle:120,1')->name('media.expert-avatar');

Route::get('/experts/{profile:slug}', [PublicExpertProfileController::class, 'show'])
    ->middleware('throttle:public-expert-profile')
    ->name('experts.show');

Route::prefix('expert')->name('expert.')
    ->middleware(['auth:sanctum', 'expert', 'abilities:expert:access', 'expert.verified', 'front:availability,expert,verifiedScopes'])
    ->group(function (): void {
        // v7: the dashboard's account header (name, avatar, KYC state, profile slug).
        Route::get('/', [\App\Http\Controllers\Api\Front\ExpertWorkController::class, 'me'])->name('me');
        Route::get('/profile', [ExpertProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ExpertProfileController::class, 'update'])
            ->middleware('throttle:expert-profile-write')
            ->name('profile.update');
        Route::post('/profile/avatar', [ExpertProfileController::class, 'uploadAvatar'])
            ->middleware('throttle:expert-profile-avatar')
            ->name('profile.avatar.store');
        Route::delete('/profile/avatar', [ExpertProfileController::class, 'deleteAvatar'])
            ->middleware('throttle:expert-profile-write')
            ->name('profile.avatar.destroy');
        Route::get('/profile/preview', [ExpertProfileController::class, 'preview'])->name('profile.preview');
        Route::post('/profile/publish', [ExpertProfileController::class, 'publish'])
            ->middleware('throttle:expert-profile-publish')
            ->name('profile.publish');
        Route::post('/profile/unpublish', [ExpertProfileController::class, 'unpublish'])
            ->middleware('throttle:expert-profile-publish')
            ->name('profile.unpublish');
        Route::get('/verified-scopes', [ExpertProfileController::class, 'scopes'])->name('verified-scopes.index');
        Route::get('/availability', [ExpertAvailabilityController::class, 'show'])->name('availability.show');
        Route::put('/availability', [ExpertAvailabilityController::class, 'update'])
            ->middleware('throttle:expert-profile-write')
            ->name('availability.update');
    });

Route::prefix('expert/kyc')->name('expert.kyc.')
    ->middleware(['auth:sanctum', 'expert', 'abilities:expert:access', 'expert.verified'])
    ->group(function (): void {
        Route::get('/', [ExpertKycController::class, 'show'])->name('show');
        Route::put('/', [ExpertKycController::class, 'update'])
            ->middleware('throttle:expert-kyc-write')
            ->name('update');
        Route::post('/documents', [ExpertKycController::class, 'upload'])
            ->middleware('throttle:expert-kyc-upload')
            ->name('documents.store');
        Route::delete('/documents/{document}', [ExpertKycController::class, 'destroy'])
            ->middleware('throttle:expert-kyc-write')
            ->name('documents.destroy');
        Route::get('/documents/{document}', [ExpertKycController::class, 'download'])
            ->name('documents.show');
        Route::post('/submit', [ExpertKycController::class, 'submit'])
            ->middleware('throttle:expert-kyc-submit')
            ->name('submit');
    });

Route::prefix('admin/auth')->name('admin.auth.')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('login');
    Route::post('/invitations/accept', [AdminAuthController::class, 'acceptInvitation'])
        ->middleware('throttle:admin-invitation-accept')
        ->name('invitations.accept');

    Route::middleware(['auth:sanctum', 'admin', 'abilities:admin:access'])->group(function (): void {
        Route::get('/me', [AdminAuthController::class, 'me'])->name('me');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AdminAuthController::class, 'logoutAll'])->name('logout-all');
    });
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'admin', 'abilities:admin:access'])
    ->group(function (): void {
        Route::get('/admins', [AdminManagementController::class, 'index'])->name('admins.index');
        Route::post('/admins', [AdminManagementController::class, 'store'])
            ->middleware('throttle:admin-management-write')
            ->name('admins.store');
        Route::get('/admins/{admin}', [AdminManagementController::class, 'show'])->name('admins.show');
        Route::match(['put', 'patch'], '/admins/{admin}', [AdminManagementController::class, 'update'])
            ->middleware('throttle:admin-management-write')
            ->name('admins.update');
        Route::patch('/admins/{admin}/status', [AdminManagementController::class, 'updateStatus'])
            ->middleware('throttle:admin-management-write')
            ->name('admins.status.update');
        Route::post('/admins/{admin}/invitation', [AdminManagementController::class, 'resendInvitation'])
            ->middleware('throttle:admin-management-write')
            ->name('admins.invitation.resend');

        Route::get('/roles', [AdminAuthorizationController::class, 'roles'])->name('roles.index');
        Route::post('/roles', [AdminAuthorizationController::class, 'storeRole'])->name('roles.store');
        Route::get('/roles/{role}', [AdminAuthorizationController::class, 'showRole'])->name('roles.show');
        Route::put('/roles/{role}', [AdminAuthorizationController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [AdminAuthorizationController::class, 'destroyRole'])
            ->name('roles.destroy');
        Route::get('/permissions', [AdminAuthorizationController::class, 'permissions'])
            ->name('permissions.index');
        Route::post('/admins/{admin}/roles/{role}', [AdminAuthorizationController::class, 'assignRole'])
            ->name('admins.roles.assign');
        Route::delete('/admins/{admin}/roles/{role}', [AdminAuthorizationController::class, 'revokeRole'])
            ->name('admins.roles.revoke');

        Route::get('/users', [\App\Http\Controllers\Api\Front\AdminController::class, 'users'])->name('users.index');
        Route::get('/users/{user}', [\App\Http\Controllers\Api\Front\AdminController::class, 'user'])->name('users.show');
        Route::get('/experts', [\App\Http\Controllers\Api\Front\AdminController::class, 'experts'])->name('experts.index');
        Route::get('/experts/{expert}', [\App\Http\Controllers\Api\Front\AdminController::class, 'expert'])->name('experts.show');
    });

Route::prefix('expert/auth')->name('expert.auth.')->group(function (): void {
    Route::post('/register', [ExpertAuthController::class, 'register'])
        ->middleware('throttle:expert-registration')
        ->name('register');
    Route::post('/login', [ExpertAuthController::class, 'login'])
        ->middleware('throttle:expert-login')
        ->name('login');
    Route::post('/forgot-password', [ExpertPasswordController::class, 'forgot'])
        ->middleware('throttle:expert-password-forgot')
        ->name('password.forgot');
    Route::post('/reset-password', [ExpertPasswordController::class, 'reset'])
        ->middleware('throttle:expert-password-reset')
        ->name('password.reset');

    Route::get('/email/verify/{id}/{hash}', [ExpertEmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('email.verify');

    Route::middleware(['auth:sanctum', 'expert', 'abilities:expert:access'])->group(function (): void {
        Route::get('/me', [ExpertAuthController::class, 'me'])->name('me');
        Route::post('/email/verification-notification', [ExpertEmailVerificationController::class, 'send'])
            ->middleware('throttle:expert-verification')
            ->name('email.send');
        Route::put('/password', [ExpertPasswordController::class, 'update'])->name('password.update');
        Route::post('/logout', [ExpertAuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [ExpertAuthController::class, 'logoutAll'])->name('logout-all');
    });
});

require __DIR__.'/privacy.php';

require __DIR__.'/expert_renewals.php';

require __DIR__.'/cases.php';

require __DIR__.'/matching.php';

require __DIR__.'/commerce.php';
require __DIR__.'/reviews.php';
require __DIR__.'/payments.php';

if (\App\Services\Payments\FakeStripeGateway::enabled()) {
    require __DIR__.'/dev.php';
}

if (app()->environment(['local', 'testing'])) {
    // Local diagnostics: open http://localhost:8000/api/__dev/openai-check (optionally ?story=...). Never routed in production.
    Route::match(['get', 'post'], '__dev/openai-check', \App\Http\Controllers\Api\Front\DevOpenAiController::class)->middleware('throttle:10,1')->name('dev.openai-check');
}
