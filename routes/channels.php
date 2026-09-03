<?php

use App\Models\User;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId): bool {
    return app(ConversationService::class)->userCanSubscribe($user, $conversationId);
});
