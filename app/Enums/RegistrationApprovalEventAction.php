<?php

namespace App\Enums;

enum RegistrationApprovalEventAction: string
{
    case Created = 'created';
    case Revoked = 'revoked';
    case Consumed = 'consumed';
}
