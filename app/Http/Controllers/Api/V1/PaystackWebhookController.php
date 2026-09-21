<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Enums\PlatformPaymentPurpose;
use App\Http\Controllers\Controller;
use App\Models\PlatformPayment;
use App\Services\Campaigns\CampaignExtensionService;
use App\Services\Campaigns\CampaignFeaturedService;
use App\Services\Certification\CertificationEnrollmentService;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PlatformPaymentGateway $gateway,
        private readonly CampaignExtensionService $extensions,
        private readonly CampaignFeaturedService $featured,
        private readonly CertificationEnrollmentService $certificationEnrollments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Paystack-Signature', '');

        if (! $this->gateway->validWebhookSignature($raw, $signature)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'Invalid webhook signature.',
                403,
            );
        }

        $payload = json_decode($raw, true);
        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        $reference = is_array($payload) && is_array($payload['data'] ?? null)
            ? (isset($payload['data']['reference']) ? (string) $payload['data']['reference'] : null)
            : null;

        try {
            $this->dispatchWebhook($event, $reference);
        } catch (HttpResponseException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            if ($status >= 500) {
                throw $exception;
            }

            Log::warning('Paystack webhook event was not applied', [
                'event' => $event,
                'reference' => $reference,
                'status' => $status,
            ]);
        }

        return ApiResponse::success(['received' => true]);
    }

    private function dispatchWebhook(string $event, ?string $reference): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        $payment = PlatformPayment::query()->where('reference', $reference)->first();

        if ($payment === null) {
            return;
        }

        match ($payment->purpose) {
            PlatformPaymentPurpose::CampaignFeatured => $this->featured->handleWebhookEvent($event, $reference),
            PlatformPaymentPurpose::CampaignExtension => $this->extensions->handleWebhookEvent($event, $reference),
            PlatformPaymentPurpose::CertificationEnrollment => $this->certificationEnrollments->handleWebhookEvent($event, $reference),
        };
    }
}
