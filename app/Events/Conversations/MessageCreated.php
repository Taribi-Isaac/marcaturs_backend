<?php

namespace App\Events\Conversations;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class MessageCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(
        private readonly Message $message,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'type' => $this->message->type->value,
            'content' => $this->message->content,
            'read_at' => $this->message->read_at?->toIso8601String(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
