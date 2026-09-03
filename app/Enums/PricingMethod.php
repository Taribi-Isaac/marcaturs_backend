<?php

namespace App\Enums;

enum PricingMethod: string
{
    case Fixed = 'fixed';
    case Quote = 'quote';
    case Other = 'other';
}
