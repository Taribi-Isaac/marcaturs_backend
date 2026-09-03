<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Http\Controllers\Controller;
use App\Services\Campaigns\CampaignExtensionService;
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
            $this->extensions->handleWebhookEvent($event, $reference);
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
}
