<?php

return [

    /*
     | Paystack is the approved provider for MarcatursHub platform purchases
     | (campaign extensions, later Featured/certification). It is not used to
     | collect customer → business or business → ambassador money.
     */
    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    /*
     | Browser return URL base for Paystack checkout (participant SPA).
     | Prefer FRONTEND_URL so local/staging/production each work without
     | hard-coding localhost. Optional PAYSTACK_CALLBACK_URL is a full-URL
     | override when FRONTEND_URL is unset (legacy).
     */
    'frontend_url' => env('FRONTEND_URL'),

    'callback_url' => env('PAYSTACK_CALLBACK_URL'),

];
