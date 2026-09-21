<?php

namespace App\Enums;

enum AdminStaffEventAction: string
{
    case Invited = 'invited';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationAccepted = 'invitation_accepted';
    case RoleChanged = 'role_changed';
    case Disabled = 'disabled';
    case Restored = 'restored';
}
