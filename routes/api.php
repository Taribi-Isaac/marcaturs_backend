<?php

use App\Http\Controllers\Api\V1\Admin\AdminCampaignController;
use App\Http\Controllers\Api\V1\Admin\AdminCampaignCoverController;
use App\Http\Controllers\Api\V1\Admin\AdminCampaignExtensionController;
use App\Http\Controllers\Api\V1\Admin\AdminCampaignFeaturedController;
use App\Http\Controllers\Api\V1\Admin\AdminCampaignMarketingResourceController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationAssessmentAttemptController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationAssessmentController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationAwardController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationCertificateController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationCurriculumController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationEnrollmentController;
use App\Http\Controllers\Api\V1\Admin\AdminCertificationProgrammeController;
use App\Http\Controllers\Api\V1\Admin\AdminConversationController;
use App\Http\Controllers\Api\V1\Admin\AdminDealController;
use App\Http\Controllers\Api\V1\Admin\AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\AdminOverviewController;
use App\Http\Controllers\Api\V1\Admin\AdminStaffController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminVerificationController;
use App\Http\Controllers\Api\V1\AmbassadorProfileController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CampaignCoverController;
use App\Http\Controllers\Api\V1\CampaignExtensionController;
use App\Http\Controllers\Api\V1\CampaignFeaturedController;
use App\Http\Controllers\Api\V1\CampaignMarketingResourceController;
use App\Http\Controllers\Api\V1\CampaignVersionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CertificationAssessmentAttemptController;
use App\Http\Controllers\Api\V1\CertificationAssessmentController;
use App\Http\Controllers\Api\V1\CertificationAwardController;
use App\Http\Controllers\Api\V1\CertificationCertificateController;
use App\Http\Controllers\Api\V1\CertificationEnrollmentController;
use App\Http\Controllers\Api\V1\CertificationLearningController;
use App\Http\Controllers\Api\V1\CertificationProgrammeController;
use App\Http\Controllers\Api\V1\CommissionController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DealCancellationController;
use App\Http\Controllers\Api\V1\DealConfirmationController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MarketplaceCampaignController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficialPaymentInformationController;
use App\Http\Controllers\Api\V1\PaymentEvidenceController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)
        ->withoutMiddleware('throttle:api')
        ->name('health');

    Route::post('/webhooks/paystack', PaystackWebhookController::class)
        ->withoutMiddleware('throttle:api')
        ->name('webhooks.paystack');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');

    Route::prefix('marketplace/campaigns')->name('marketplace.campaigns.')->group(function (): void {
        Route::get('/', [MarketplaceCampaignController::class, 'index'])->name('index');
        Route::get('/{campaign}/cover', [MarketplaceCampaignController::class, 'cover'])
            ->whereNumber('campaign')
            ->name('cover');
        Route::get('/{campaign}', [MarketplaceCampaignController::class, 'show'])
            ->whereNumber('campaign')
            ->name('show');
    });

    Route::get('/public/official-payment-information/{token}', [OfficialPaymentInformationController::class, 'show'])
        ->middleware('throttle:official-payment')
        ->where('token', '[A-Za-z0-9]+')
        ->name('public.official-payment-information.show');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:registration')
            ->name('register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:password-reset')
            ->name('forgot-password');

        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:password-reset')
            ->name('reset-password');

        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:email-verification'])
            ->name('email.verify');

        Route::post('/staff-invitations/accept', [AdminStaffController::class, 'acceptInvitation'])
            ->middleware('throttle:password-reset')
            ->name('auth.staff-invitations.accept');

        Route::middleware(['auth:sanctum', 'account.access'])->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');

            Route::post('/change-password', [AuthController::class, 'changePassword'])
                ->middleware('throttle:change-password')
                ->name('change-password');

            Route::post('/email/verification-notification', [AuthController::class, 'resendEmailVerification'])
                ->middleware('throttle:email-verification')
                ->name('email.verification-notification');
        });
    });

    Route::middleware(['auth:sanctum', 'account.access', 'email.verified'])->group(function (): void {
        Route::middleware('role:BUSINESS')->prefix('businesses')->name('businesses.')->group(function (): void {
            Route::get('/me', [BusinessProfileController::class, 'show'])->name('me.show');
            Route::post('/me', [BusinessProfileController::class, 'store'])->name('me.store');
            Route::patch('/me', [BusinessProfileController::class, 'update'])->name('me.update');
        });

        Route::middleware('role:AMBASSADOR')->prefix('ambassadors')->name('ambassadors.')->group(function (): void {
            Route::get('/me', [AmbassadorProfileController::class, 'show'])->name('me.show');
            Route::post('/me', [AmbassadorProfileController::class, 'store'])->name('me.store');
            Route::patch('/me', [AmbassadorProfileController::class, 'update'])->name('me.update');
        });

        Route::middleware('role:AMBASSADOR')->prefix('certification/programmes')->name('certification.programmes.')->group(function (): void {
            Route::get('/', [CertificationProgrammeController::class, 'index'])->name('index');
            Route::get('/{programme}', [CertificationProgrammeController::class, 'show'])
                ->whereNumber('programme')
                ->name('show');
            Route::post('/{programme}/purchase/initialize', [CertificationEnrollmentController::class, 'initialize'])
                ->whereNumber('programme')
                ->middleware('throttle:api')
                ->name('purchase.initialize');
        });

        Route::middleware('role:AMBASSADOR')->prefix('certification')->name('certification.')->group(function (): void {
            Route::get('/enrollments', [CertificationEnrollmentController::class, 'index'])->name('enrollments.index');
            Route::get('/enrollments/{enrollment}', [CertificationEnrollmentController::class, 'show'])
                ->whereNumber('enrollment')
                ->name('enrollments.show');
            Route::get('/enrollments/{enrollment}/curriculum', [CertificationLearningController::class, 'curriculum'])
                ->whereNumber('enrollment')
                ->name('enrollments.curriculum');
            Route::get('/enrollments/{enrollment}/progress', [CertificationLearningController::class, 'progress'])
                ->whereNumber('enrollment')
                ->name('enrollments.progress');
            Route::get('/enrollments/{enrollment}/assessment', [CertificationAssessmentController::class, 'show'])
                ->whereNumber('enrollment')
                ->name('enrollments.assessment');
            Route::get('/enrollments/{enrollment}/assessment/attempts', [CertificationAssessmentAttemptController::class, 'index'])
                ->whereNumber('enrollment')
                ->name('enrollments.assessment.attempts.index');
            Route::post('/enrollments/{enrollment}/assessment/attempts', [CertificationAssessmentAttemptController::class, 'store'])
                ->whereNumber('enrollment')
                ->middleware('throttle:api')
                ->name('enrollments.assessment.attempts.store');
            Route::get('/enrollments/{enrollment}/assessment/attempts/{attempt}', [CertificationAssessmentAttemptController::class, 'show'])
                ->whereNumber(['enrollment', 'attempt'])
                ->name('enrollments.assessment.attempts.show');
            Route::post('/enrollments/{enrollment}/assessment/attempts/{attempt}/submit', [CertificationAssessmentAttemptController::class, 'submit'])
                ->whereNumber(['enrollment', 'attempt'])
                ->middleware('throttle:api')
                ->name('enrollments.assessment.attempts.submit');
            Route::post('/enrollments/{enrollment}/lessons/{lesson}/complete', [CertificationLearningController::class, 'completeLesson'])
                ->whereNumber('enrollment')
                ->whereNumber('lesson')
                ->middleware('throttle:api')
                ->name('enrollments.lessons.complete');
            Route::get('/enrollments/{enrollment}/lessons/{lesson}/resources/{resource}/download', [CertificationLearningController::class, 'downloadResource'])
                ->whereNumber('enrollment')
                ->whereNumber('lesson')
                ->whereNumber('resource')
                ->name('enrollments.lessons.resources.download');
            Route::post('/purchases/verify', [CertificationEnrollmentController::class, 'verify'])
                ->name('purchases.verify');
            Route::get('/awards', [CertificationAwardController::class, 'index'])->name('awards.index');
            Route::get('/awards/{award}', [CertificationAwardController::class, 'show'])
                ->whereNumber('award')
                ->name('awards.show');
            Route::get('/certificates', [CertificationCertificateController::class, 'index'])->name('certificates.index');
            Route::get('/certificates/{certificate}', [CertificationCertificateController::class, 'show'])
                ->whereNumber('certificate')
                ->name('certificates.show');
            Route::get('/certificates/{certificate}/download', [CertificationCertificateController::class, 'download'])
                ->whereNumber('certificate')
                ->name('certificates.download');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('verification')->name('verification.')->group(function (): void {
            Route::get('/requirements', [VerificationController::class, 'requirements'])->name('requirements');
            Route::get('/status', [VerificationController::class, 'status'])->name('status');
            Route::post('/submissions', [VerificationController::class, 'store'])
                ->middleware('throttle:uploads')
                ->name('submissions.store');
            Route::patch('/submissions/{submission}', [VerificationController::class, 'resubmit'])
                ->middleware('throttle:uploads')
                ->name('submissions.resubmit');
        });

        Route::middleware('role:AMBASSADOR')->prefix('marketplace/campaigns')->name('marketplace.campaigns.')->group(function (): void {
            Route::get('/{campaign}/resources/{resource}/download', [MarketplaceCampaignController::class, 'downloadResource'])
                ->name('resources.download');
        });

        Route::middleware('role:AMBASSADOR')->post('/deals', [DealController::class, 'store'])->name('deals.store');

        Route::middleware(['role:AMBASSADOR', 'throttle:uploads'])->post('/deals/{deal}/payment-evidence', [PaymentEvidenceController::class, 'store'])
            ->name('deals.payment-evidence.store');

        Route::middleware('role:BUSINESS')->prefix('deals')->name('deals.')->scopeBindings()->group(function (): void {
            Route::post('/{deal}/confirm', [DealConfirmationController::class, 'confirm'])->name('confirm');
            Route::post('/{deal}/payment-evidence/{paymentEvidence}/reject', [DealConfirmationController::class, 'reject'])
                ->name('payment-evidence.reject');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('deals')->name('deals.')->scopeBindings()->group(function (): void {
            Route::get('/', [DealController::class, 'index'])->name('index');
            Route::get('/{deal}', [DealController::class, 'show'])->name('show');
            Route::post('/{deal}/cancel', [DealCancellationController::class, 'cancel'])->name('cancel');
            Route::get('/{deal}/payment-evidence', [PaymentEvidenceController::class, 'index'])->name('payment-evidence.index');
            Route::get('/{deal}/payment-evidence/{paymentEvidence}', [PaymentEvidenceController::class, 'show'])->name('payment-evidence.show');
            Route::get('/{deal}/payment-evidence/{paymentEvidence}/download', [PaymentEvidenceController::class, 'download'])->name('payment-evidence.download');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('commissions')->name('commissions.')->group(function (): void {
            Route::get('/', [CommissionController::class, 'index'])->name('index');
            Route::get('/{commission}', [CommissionController::class, 'show'])->name('show');
        });

        Route::middleware('role:BUSINESS')->prefix('commissions')->name('commissions.')->group(function (): void {
            Route::post('/{commission}/mark-paid', [CommissionController::class, 'markPaid'])->name('mark-paid');
        });

        Route::middleware('role:AMBASSADOR')->prefix('commissions')->name('commissions.')->group(function (): void {
            Route::post('/{commission}/confirm-received', [CommissionController::class, 'confirmReceived'])->name('confirm-received');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('dispute-categories')->name('dispute-categories.')->group(function (): void {
            Route::get('/', [DisputeController::class, 'categories'])->name('index');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('disputes')->name('disputes.')->scopeBindings()->group(function (): void {
            Route::get('/', [DisputeController::class, 'index'])->name('index');
            Route::get('/{dispute}', [DisputeController::class, 'show'])->name('show');
            Route::post('/{dispute}/attachments', [DisputeController::class, 'storeAttachment'])
                ->middleware('throttle:uploads')
                ->name('attachments.store');
            Route::get('/{dispute}/attachments/{attachment}/download', [DisputeController::class, 'downloadAttachment'])
                ->name('attachments.download');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->post('/deals/{deal}/disputes', [DisputeController::class, 'store'])
            ->name('deals.disputes.store');

        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/{notification}', [NotificationController::class, 'show'])->name('show');
            Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
        });

        Route::middleware('role:BUSINESS,AMBASSADOR')->prefix('conversations')->name('conversations.')->group(function (): void {
            Route::get('/', [ConversationController::class, 'index'])->name('index');
            Route::post('/', [ConversationController::class, 'store'])->name('store');
            Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show');
            Route::get('/{conversation}/messages', [ConversationController::class, 'messages'])->name('messages.index');
            Route::post('/{conversation}/messages', [ConversationController::class, 'send'])->name('messages.store');
            Route::post('/{conversation}/read', [ConversationController::class, 'markRead'])->name('read');
            Route::post('/{conversation}/report', [ConversationController::class, 'report'])->name('report');
        });

        Route::middleware('role:BUSINESS')->prefix('campaigns')->name('campaigns.')->scopeBindings()->group(function (): void {
            Route::get('/', [CampaignController::class, 'index'])->name('index');
            Route::post('/', [CampaignController::class, 'store'])->name('store');
            Route::get('/{campaign}', [CampaignController::class, 'show'])->name('show');
            Route::patch('/{campaign}', [CampaignController::class, 'update'])->name('update');
            Route::post('/{campaign}/submit', [CampaignController::class, 'submit'])->name('submit');
            Route::post('/{campaign}/deactivate', [CampaignController::class, 'deactivate'])->name('deactivate');
            Route::get('/{campaign}/extension-packages', [CampaignExtensionController::class, 'packages'])->name('extension-packages');
            Route::get('/{campaign}/extensions', [CampaignExtensionController::class, 'index'])->name('extensions.index');
            Route::post('/{campaign}/extensions/initialize', [CampaignExtensionController::class, 'initialize'])->name('extensions.initialize');
            Route::post('/{campaign}/extensions/verify', [CampaignExtensionController::class, 'verify'])->name('extensions.verify');
            Route::get('/{campaign}/featured', [CampaignFeaturedController::class, 'show'])->name('featured.show');
            Route::post('/{campaign}/featured/initialize', [CampaignFeaturedController::class, 'initialize'])->name('featured.initialize');
            Route::post('/{campaign}/featured/verify', [CampaignFeaturedController::class, 'verify'])->name('featured.verify');
            Route::get('/{campaign}/versions', [CampaignVersionController::class, 'index'])->name('versions.index');
            Route::post('/{campaign}/versions', [CampaignVersionController::class, 'store'])->name('versions.store');
            Route::get('/{campaign}/versions/{version}', [CampaignVersionController::class, 'show'])->name('versions.show');
            Route::patch('/{campaign}/versions/{version}', [CampaignVersionController::class, 'update'])->name('versions.update');
            Route::post('/{campaign}/versions/{version}/publish', [CampaignVersionController::class, 'publish'])->name('versions.publish');
            Route::get('/{campaign}/resources', [CampaignMarketingResourceController::class, 'index'])->name('resources.index');
            Route::post('/{campaign}/resources', [CampaignMarketingResourceController::class, 'store'])
                ->middleware('throttle:uploads')
                ->name('resources.store');
            Route::get('/{campaign}/resources/{marketingResource}', [CampaignMarketingResourceController::class, 'show'])->name('resources.show');
            Route::patch('/{campaign}/resources/{marketingResource}', [CampaignMarketingResourceController::class, 'update'])
                ->middleware('throttle:uploads')
                ->name('resources.update');
            Route::delete('/{campaign}/resources/{marketingResource}', [CampaignMarketingResourceController::class, 'destroy'])->name('resources.destroy');
            Route::get('/{campaign}/resources/{marketingResource}/download', [CampaignMarketingResourceController::class, 'download'])->name('resources.download');
            Route::get('/{campaign}/cover', [CampaignCoverController::class, 'show'])->name('cover.show');
            Route::post('/{campaign}/cover', [CampaignCoverController::class, 'store'])
                ->middleware('throttle:uploads')
                ->name('cover.store');
            Route::delete('/{campaign}/cover', [CampaignCoverController::class, 'destroy'])->name('cover.destroy');
            Route::get('/{campaign}/cover/download', [CampaignCoverController::class, 'download'])->name('cover.download');
        });

        Route::middleware('role:BUSINESS')->prefix('campaign-featured')->name('campaign-featured.')->group(function (): void {
            Route::get('/packages', [CampaignFeaturedController::class, 'packages'])->name('packages');
        });

        Route::middleware('role:ADMIN')->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/overview', [AdminOverviewController::class, 'show'])
                ->middleware('permission:overview.view')
                ->name('overview');
        });

        Route::middleware(['role:ADMIN', 'permission:staff.view'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
            Route::get('/', [AdminStaffController::class, 'index'])->name('index');
            Route::get('/{user}', [AdminStaffController::class, 'show'])->whereNumber('user')->name('show');
        });

        Route::middleware(['role:ADMIN', 'permission:staff.manage'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
            Route::post('/invitations', [AdminStaffController::class, 'invite'])
                ->middleware('throttle:registration')
                ->name('invitations.store');
            Route::post('/invitations/{invitation}/revoke', [AdminStaffController::class, 'revokeInvitation'])
                ->whereNumber('invitation')
                ->name('invitations.revoke');
            Route::patch('/{user}', [AdminStaffController::class, 'changeRole'])->whereNumber('user')->name('update');
            Route::post('/{user}/disable', [AdminStaffController::class, 'disable'])->whereNumber('user')->name('disable');
            Route::post('/{user}/restore', [AdminStaffController::class, 'restore'])->whereNumber('user')->name('restore');
            Route::post('/direct', [AdminStaffController::class, 'createDirect'])
                ->middleware('throttle:registration')
                ->name('direct');
        });

        Route::middleware(['role:ADMIN', 'permission:users.view'])->prefix('admin/users')->name('admin.users.')->group(function (): void {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');
            Route::get('/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('show');
        });

        Route::middleware(['role:ADMIN', 'permission:certification.view'])
            ->prefix('admin/certification/programmes')
            ->name('admin.certification.programmes.')
            ->scopeBindings()
            ->group(function (): void {
                Route::get('/', [AdminCertificationProgrammeController::class, 'index'])->name('index');
                Route::get('/{programme}', [AdminCertificationProgrammeController::class, 'show'])
                    ->whereNumber('programme')
                    ->name('show');
                Route::get('/{programme}/versions', [AdminCertificationProgrammeController::class, 'indexVersions'])
                    ->whereNumber('programme')
                    ->name('versions.index');
                Route::get('/{programme}/versions/{version}', [AdminCertificationProgrammeController::class, 'showVersion'])
                    ->whereNumber('programme')
                    ->name('versions.show');

                Route::get('/{programme}/versions/{version}/modules', [AdminCertificationCurriculumController::class, 'indexModules'])
                    ->whereNumber('programme')
                    ->name('versions.modules.index');
                Route::get('/{programme}/versions/{version}/modules/{module}', [AdminCertificationCurriculumController::class, 'showModule'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.show');
                Route::get('/{programme}/versions/{version}/modules/{module}/lessons', [AdminCertificationCurriculumController::class, 'indexLessons'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.lessons.index');
                Route::get('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}', [AdminCertificationCurriculumController::class, 'showLesson'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->name('versions.modules.lessons.show');
                Route::get('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources', [AdminCertificationCurriculumController::class, 'indexResources'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->name('versions.modules.lessons.resources.index');
                Route::get('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources/{resource}', [AdminCertificationCurriculumController::class, 'showResource'])
                    ->whereNumber(['programme', 'module', 'lesson', 'resource'])
                    ->name('versions.modules.lessons.resources.show');
                Route::get('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources/{resource}/download', [AdminCertificationCurriculumController::class, 'downloadResource'])
                    ->whereNumber(['programme', 'module', 'lesson', 'resource'])
                    ->name('versions.modules.lessons.resources.download');

                Route::get('/{programme}/versions/{version}/assessment', [AdminCertificationAssessmentController::class, 'show'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.show');
                Route::get('/{programme}/versions/{version}/assessment/questions', [AdminCertificationAssessmentController::class, 'indexQuestions'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.questions.index');
                Route::get('/{programme}/versions/{version}/assessment/questions/{question}', [AdminCertificationAssessmentController::class, 'showQuestion'])
                    ->whereNumber(['programme', 'question'])
                    ->name('versions.assessment.questions.show');
            });

        Route::middleware(['role:ADMIN', 'permission:certification.manage'])
            ->prefix('admin/certification/programmes')
            ->name('admin.certification.programmes.')
            ->scopeBindings()
            ->group(function (): void {
                Route::post('/', [AdminCertificationProgrammeController::class, 'store'])->name('store');
                Route::patch('/{programme}', [AdminCertificationProgrammeController::class, 'update'])
                    ->whereNumber('programme')
                    ->name('update');
                Route::post('/{programme}/versions', [AdminCertificationProgrammeController::class, 'storeVersion'])
                    ->whereNumber('programme')
                    ->name('versions.store');
                Route::patch('/{programme}/versions/{version}', [AdminCertificationProgrammeController::class, 'updateVersion'])
                    ->whereNumber('programme')
                    ->name('versions.update');
                Route::post('/{programme}/versions/{version}/publish', [AdminCertificationProgrammeController::class, 'publishVersion'])
                    ->whereNumber('programme')
                    ->name('versions.publish');
                Route::post('/{programme}/versions/{version}/unpublish', [AdminCertificationProgrammeController::class, 'unpublishVersion'])
                    ->whereNumber('programme')
                    ->name('versions.unpublish');

                Route::post('/{programme}/versions/{version}/assessment', [AdminCertificationAssessmentController::class, 'store'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.store');
                Route::patch('/{programme}/versions/{version}/assessment', [AdminCertificationAssessmentController::class, 'update'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.update');
                Route::post('/{programme}/versions/{version}/assessment/questions', [AdminCertificationAssessmentController::class, 'storeQuestion'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.questions.store');
                Route::patch('/{programme}/versions/{version}/assessment/questions/{question}', [AdminCertificationAssessmentController::class, 'updateQuestion'])
                    ->whereNumber(['programme', 'question'])
                    ->name('versions.assessment.questions.update');
                Route::delete('/{programme}/versions/{version}/assessment/questions/{question}', [AdminCertificationAssessmentController::class, 'destroyQuestion'])
                    ->whereNumber(['programme', 'question'])
                    ->name('versions.assessment.questions.destroy');
                Route::post('/{programme}/versions/{version}/assessment/questions/reorder', [AdminCertificationAssessmentController::class, 'reorderQuestions'])
                    ->whereNumber('programme')
                    ->name('versions.assessment.questions.reorder');

                Route::post('/{programme}/versions/{version}/modules', [AdminCertificationCurriculumController::class, 'storeModule'])
                    ->whereNumber('programme')
                    ->name('versions.modules.store');
                Route::patch('/{programme}/versions/{version}/modules/{module}', [AdminCertificationCurriculumController::class, 'updateModule'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.update');
                Route::delete('/{programme}/versions/{version}/modules/{module}', [AdminCertificationCurriculumController::class, 'destroyModule'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.destroy');
                Route::post('/{programme}/versions/{version}/modules/reorder', [AdminCertificationCurriculumController::class, 'reorderModules'])
                    ->whereNumber('programme')
                    ->name('versions.modules.reorder');

                Route::post('/{programme}/versions/{version}/modules/{module}/lessons', [AdminCertificationCurriculumController::class, 'storeLesson'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.lessons.store');
                Route::patch('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}', [AdminCertificationCurriculumController::class, 'updateLesson'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->name('versions.modules.lessons.update');
                Route::delete('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}', [AdminCertificationCurriculumController::class, 'destroyLesson'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->name('versions.modules.lessons.destroy');
                Route::post('/{programme}/versions/{version}/modules/{module}/lessons/reorder', [AdminCertificationCurriculumController::class, 'reorderLessons'])
                    ->whereNumber(['programme', 'module'])
                    ->name('versions.modules.lessons.reorder');

                Route::post('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources', [AdminCertificationCurriculumController::class, 'storeResource'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->middleware('throttle:uploads')
                    ->name('versions.modules.lessons.resources.store');
                Route::post('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources/{resource}', [AdminCertificationCurriculumController::class, 'updateResource'])
                    ->whereNumber(['programme', 'module', 'lesson', 'resource'])
                    ->middleware('throttle:uploads')
                    ->name('versions.modules.lessons.resources.update');
                Route::delete('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources/{resource}', [AdminCertificationCurriculumController::class, 'destroyResource'])
                    ->whereNumber(['programme', 'module', 'lesson', 'resource'])
                    ->name('versions.modules.lessons.resources.destroy');
                Route::post('/{programme}/versions/{version}/modules/{module}/lessons/{lesson}/resources/reorder', [AdminCertificationCurriculumController::class, 'reorderResources'])
                    ->whereNumber(['programme', 'module', 'lesson'])
                    ->name('versions.modules.lessons.resources.reorder');
            });

        Route::middleware(['role:ADMIN', 'permission:certification.learners.view'])
            ->prefix('admin/certification/enrollments')
            ->name('admin.certification.enrollments.')
            ->group(function (): void {
                Route::get('/', [AdminCertificationEnrollmentController::class, 'index'])->name('index');
                Route::get('/{enrollment}', [AdminCertificationEnrollmentController::class, 'show'])
                    ->whereNumber('enrollment')
                    ->name('show');
                Route::get('/{enrollment}/assessment/attempts', [AdminCertificationAssessmentAttemptController::class, 'index'])
                    ->whereNumber('enrollment')
                    ->name('assessment.attempts.index');
                Route::get('/{enrollment}/assessment/attempts/{attempt}', [AdminCertificationAssessmentAttemptController::class, 'show'])
                    ->whereNumber(['enrollment', 'attempt'])
                    ->name('assessment.attempts.show');
                Route::get('/{enrollment}/awards', [AdminCertificationAwardController::class, 'indexForEnrollment'])
                    ->whereNumber('enrollment')
                    ->name('awards.index');
                Route::get('/{enrollment}/certificates', [AdminCertificationCertificateController::class, 'indexForEnrollment'])
                    ->whereNumber('enrollment')
                    ->name('certificates.index');
            });

        Route::middleware(['role:ADMIN', 'permission:certification.learners.view'])
            ->prefix('admin/certification/awards')
            ->name('admin.certification.awards.')
            ->group(function (): void {
                Route::get('/{award}', [AdminCertificationAwardController::class, 'show'])
                    ->whereNumber('award')
                    ->name('show');
            });

        Route::middleware(['role:ADMIN'])
            ->prefix('admin/certification/certificates')
            ->name('admin.certification.certificates.')
            ->group(function (): void {
                Route::get('/{certificate}', [AdminCertificationCertificateController::class, 'show'])
                    ->middleware('permission:certification.learners.view')
                    ->whereNumber('certificate')
                    ->name('show');
                Route::get('/{certificate}/download', [AdminCertificationCertificateController::class, 'download'])
                    ->middleware('permission:certification.learners.view')
                    ->whereNumber('certificate')
                    ->name('download');
                Route::post('/{certificate}/artifact/retry', [AdminCertificationCertificateController::class, 'retryArtifact'])
                    ->middleware(['permission:certification.manage', 'throttle:api'])
                    ->whereNumber('certificate')
                    ->name('artifact.retry');
            });

        Route::middleware(['role:ADMIN', 'permission:users.manage'])->prefix('admin/users')->name('admin.users.')->group(function (): void {
            Route::post('/{user}/restrict', [AdminUserController::class, 'restrict'])->whereNumber('user')->name('restrict');
            Route::post('/{user}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('user')->name('suspend');
            Route::post('/{user}/restore', [AdminUserController::class, 'restore'])->whereNumber('user')->name('restore');
            Route::post('/{user}/ban', [AdminUserController::class, 'ban'])->whereNumber('user')->name('ban');
        });

        Route::middleware(['role:ADMIN', 'permission:deals.view'])->prefix('admin/deals')->name('admin.deals.')->group(function (): void {
            Route::get('/', [AdminDealController::class, 'index'])->name('index');
            Route::get('/{deal}', [AdminDealController::class, 'show'])->whereNumber('deal')->name('show');
            Route::get('/{deal}/payment-evidence/{paymentEvidence}/download', [AdminDealController::class, 'downloadEvidence'])
                ->whereNumber(['deal', 'paymentEvidence'])
                ->name('payment-evidence.download');
        });

        Route::middleware(['role:ADMIN', 'permission:campaigns.view'])->prefix('admin/campaigns')->name('admin.campaigns.')->group(function (): void {
            Route::get('/', [AdminCampaignController::class, 'index'])->name('index');
            Route::get('/{campaign}', [AdminCampaignController::class, 'show'])->name('show');
            Route::get('/{campaign}/extensions', [AdminCampaignExtensionController::class, 'indexExtensions'])->name('extensions.index');
            Route::get('/{campaign}/featured', [AdminCampaignFeaturedController::class, 'indexPurchases'])->name('featured.index');
            Route::get('/{campaign}/resources', [AdminCampaignMarketingResourceController::class, 'index'])->name('resources.index');
            Route::get('/{campaign}/resources/{marketingResource}/download', [AdminCampaignMarketingResourceController::class, 'download'])->name('resources.download');
            Route::get('/{campaign}/cover', [AdminCampaignCoverController::class, 'show'])->name('cover.show');
            Route::get('/{campaign}/cover/download', [AdminCampaignCoverController::class, 'download'])->name('cover.download');
        });

        Route::middleware(['role:ADMIN', 'permission:campaigns.manage'])->prefix('admin/campaigns')->name('admin.campaigns.')->group(function (): void {
            Route::post('/{campaign}/approve', [AdminCampaignController::class, 'approve'])->name('approve');
            Route::post('/{campaign}/reject', [AdminCampaignController::class, 'reject'])->name('reject');
            Route::post('/{campaign}/request-modification', [AdminCampaignController::class, 'requestModification'])->name('request-modification');
            Route::post('/{campaign}/activate', [AdminCampaignController::class, 'activate'])->name('activate');
            Route::post('/{campaign}/suspend', [AdminCampaignController::class, 'suspend'])->name('suspend');
            Route::post('/{campaign}/close', [AdminCampaignController::class, 'close'])->name('close');
        });

        Route::middleware(['role:ADMIN', 'permission:configuration.manage'])->prefix('admin/campaign-extension-packages')->name('admin.campaign-extension-packages.')->group(function (): void {
            Route::get('/', [AdminCampaignExtensionController::class, 'indexPackages'])->name('index');
            Route::post('/', [AdminCampaignExtensionController::class, 'storePackage'])->name('store');
            Route::patch('/{package}', [AdminCampaignExtensionController::class, 'updatePackage'])->name('update');
        });

        Route::middleware(['role:ADMIN', 'permission:configuration.manage'])->prefix('admin/campaign-featured-packages')->name('admin.campaign-featured-packages.')->group(function (): void {
            Route::get('/', [AdminCampaignFeaturedController::class, 'indexPackages'])->name('index');
            Route::post('/', [AdminCampaignFeaturedController::class, 'storePackage'])->name('store');
            Route::patch('/{package}', [AdminCampaignFeaturedController::class, 'updatePackage'])->name('update');
        });

        Route::middleware(['role:ADMIN', 'permission:conversations.moderate'])->prefix('admin/conversations')->name('admin.conversations.')->group(function (): void {
            Route::get('/', [AdminConversationController::class, 'index'])->name('index');
            Route::get('/{conversation}', [AdminConversationController::class, 'show'])->name('show');
            Route::get('/{conversation}/messages', [AdminConversationController::class, 'messages'])->name('messages.index');
        });

        Route::middleware(['role:ADMIN', 'permission:configuration.manage'])->prefix('admin/dispute-categories')->name('admin.dispute-categories.')->group(function (): void {
            Route::get('/', [AdminDisputeController::class, 'indexCategories'])->name('index');
            Route::post('/', [AdminDisputeController::class, 'storeCategory'])->name('store');
            Route::patch('/{disputeCategory}', [AdminDisputeController::class, 'updateCategory'])->name('update');
        });

        Route::middleware(['role:ADMIN', 'permission:disputes.view'])->prefix('admin/disputes')->name('admin.disputes.')->scopeBindings()->group(function (): void {
            Route::get('/', [AdminDisputeController::class, 'index'])->name('index');
            Route::get('/{dispute}', [AdminDisputeController::class, 'show'])->name('show');
            Route::get('/{dispute}/attachments/{attachment}/download', [AdminDisputeController::class, 'downloadAttachment'])
                ->name('attachments.download');
        });

        Route::middleware(['role:ADMIN', 'permission:disputes.manage'])->prefix('admin/disputes')->name('admin.disputes.')->scopeBindings()->group(function (): void {
            Route::post('/{dispute}/start-review', [AdminDisputeController::class, 'startReview'])->name('start-review');
            Route::post('/{dispute}/request-evidence', [AdminDisputeController::class, 'requestEvidence'])->name('request-evidence');
            Route::post('/{dispute}/resume-review', [AdminDisputeController::class, 'resumeReview'])->name('resume-review');
            Route::post('/{dispute}/mark-decision-pending', [AdminDisputeController::class, 'markDecisionPending'])->name('mark-decision-pending');
            Route::post('/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->name('resolve');
            Route::post('/{dispute}/close', [AdminDisputeController::class, 'close'])->name('close');
            Route::post('/{dispute}/attachments', [AdminDisputeController::class, 'storeAttachment'])
                ->middleware('throttle:uploads')
                ->name('attachments.store');
        });

        Route::middleware(['role:ADMIN', 'permission:configuration.manage'])->prefix('admin/categories')->name('admin.categories.')->group(function (): void {
            Route::get('/', [AdminCategoryController::class, 'index'])->name('index');
            Route::post('/', [AdminCategoryController::class, 'store'])->name('store');
            Route::patch('/{category}', [AdminCategoryController::class, 'update'])->name('update');
        });

        Route::middleware(['role:ADMIN', 'permission:verification.view'])->prefix('admin/verification')->name('admin.verification.')->group(function (): void {
            Route::get('/requirements', [AdminVerificationController::class, 'indexRequirements'])->name('requirements.index');
            Route::get('/submissions', [AdminVerificationController::class, 'indexSubmissions'])->name('submissions.index');
            Route::get('/submissions/{submission}', [AdminVerificationController::class, 'showSubmission'])->name('submissions.show');
            Route::get('/submissions/{submission}/events', [AdminVerificationController::class, 'events'])->name('submissions.events');
            Route::get('/submissions/{submission}/evidence/{evidence}/download', [AdminVerificationController::class, 'downloadEvidence'])->name('submissions.evidence.download');
        });

        Route::middleware(['role:ADMIN', 'permission:verification.configure'])->prefix('admin/verification')->name('admin.verification.')->group(function (): void {
            Route::post('/requirements', [AdminVerificationController::class, 'storeRequirement'])->name('requirements.store');
            Route::patch('/requirements/{requirement}', [AdminVerificationController::class, 'updateRequirement'])->name('requirements.update');
        });

        Route::middleware(['role:ADMIN', 'permission:verification.review'])->prefix('admin/verification')->name('admin.verification.')->group(function (): void {
            Route::post('/submissions/{submission}/start-review', [AdminVerificationController::class, 'startReview'])->name('submissions.start-review');
            Route::post('/submissions/{submission}/approve', [AdminVerificationController::class, 'approve'])->name('submissions.approve');
            Route::post('/submissions/{submission}/reject', [AdminVerificationController::class, 'reject'])->name('submissions.reject');
            Route::post('/submissions/{submission}/request-information', [AdminVerificationController::class, 'requestInformation'])->name('submissions.request-information');
        });
    });
});
