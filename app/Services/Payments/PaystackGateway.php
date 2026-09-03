<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Services\Payments\Data\PaymentInitialization;
use App\Services\Payments\Data\PaymentVerification;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaystackGateway implements PlatformPaymentGateway
{
    public function initialize(
        string $email,
        int $amountMinor,
        string $currency,
        string $reference,
        ?string $callbackUrl,
        array $metadata,
    ): PaymentInitialization {
        $payload = [
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'metadata' => $metadata,
        ];

        if (is_string($callbackUrl) && $callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        try {
            $response = $this->client()->post('/transaction/initialize', $payload);
            $response->throw();
        } catch (RequestException $exception) {
            Log::warning('Paystack initialize failed', [
                'reference' => $reference,
                'status' => $exception->response?->status(),
            ]);

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                'The payment provider could not initialize this transaction.',
                503,
            ));
        } catch (Throwable $exception) {
            Log::warning('Paystack initialize error', ['reference' => $reference]);

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                'The payment provider could not initialize this transaction.',
                503,
            ));
        }

        $data = $response->json('data');

        if (! is_array($data) || ! is_string($data['authorization_url'] ?? null)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                'The payment provider returned an invalid initialization response.',
                503,
            ));
        }

        return new PaymentInitialization(
            reference: (string) ($data['reference'] ?? $reference),
            authorizationUrl: (string) $data['authorization_url'],
            accessCode: isset($data['access_code']) ? (string) $data['access_code'] : null,
        );
    }

    public function verify(string $reference): PaymentVerification
    {
        try {
            $response = $this->client()->get('/transaction/verify/'.$reference);
            $response->throw();
        } catch (Throwable) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                'The payment provider could not verify this transaction.',
                503,
            ));
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                'The payment provider returned an invalid verification response.',
                503,
            ));
        }

        return new PaymentVerification(
            reference: (string) ($data['reference'] ?? $reference),
            status: strtolower((string) ($data['status'] ?? 'failed')),
            amountMinor: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
        );
    }

    public function validWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('paystack.secret_key');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function client(): PendingRequest
    {
        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        return Http::baseUrl((string) config('paystack.base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
