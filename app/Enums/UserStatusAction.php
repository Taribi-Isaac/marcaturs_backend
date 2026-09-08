<?php

namespace App\Enums;

enum UserStatusAction: string
{
    case Restrict = 'restrict';

    case Suspend = 'suspend';

    case Restore = 'restore';

    case Ban = 'ban';
}
