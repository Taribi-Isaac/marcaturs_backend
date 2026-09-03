<?php

namespace App\Services\Conversations;

use App\Events\Conversations\MessageCreated;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessageCreatedBroadcaster
{
    public function publish(Message $message): void
    {
        try {
            broadcast(new MessageCreated($message));
        } catch (Throwable $exception) {
            Log::warning('Chat message broadcast failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
