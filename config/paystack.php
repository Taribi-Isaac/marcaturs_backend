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

    'callback_url' => env('PAYSTACK_CALLBACK_URL'),

];
