<?php

namespace App\Support\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Str;

final class OfficialPaymentShare
{
    public static function ensureToken(Campaign $campaign): string
    {
        if (is_string($campaign->official_payment_token) && $campaign->official_payment_token !== '') {
            return $campaign->official_payment_token;
        }

        do {
            $token = Str::lower(Str::random(48));
        } while (Campaign::query()->where('official_payment_token', $token)->exists());

        Campaign::query()->whereKey($campaign->getKey())->update([
            'official_payment_token' => $token,
            'updated_at' => now(),
        ]);
        $campaign->setAttribute('official_payment_token', $token);
        $campaign->syncOriginalAttribute('official_payment_token');

        return $token;
    }

    /**
     * @return array{token: string, path: string, share_path: string, share_url: string|null}
     */
    public static function references(Campaign $campaign): array
    {
        $token = self::ensureToken($campaign);
        $apiPath = '/api/v1/public/official-payment-information/'.$token;
        $sharePath = '/pay/'.$token;
        $frontend = rtrim((string) env('FRONTEND_URL', ''), '/');

        return [
            'token' => $token,
            'path' => $apiPath,
            'share_path' => $sharePath,
            'share_url' => $frontend !== '' ? $frontend.$sharePath : null,
        ];
    }
}
