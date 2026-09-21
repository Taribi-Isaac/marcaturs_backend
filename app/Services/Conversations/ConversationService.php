<?php

namespace App\Services\Conversations;

use App\Enums\AdminPermission;
use App\Enums\MessageType;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationService
{
    public function __construct(
        private readonly MessageCreatedBroadcaster $messageCreatedBroadcaster,
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @return array{0: Conversation, 1: bool}
     */
    public function open(User $user, array $attributes): array
    {
        if ($user->isAmbassador()) {
            return $this->openAsAmbassador($user, $attributes);
        }

        if ($user->isBusiness()) {
            return $this->openAsBusiness($user, $attributes);
        }

        throw new AuthorizationException('You are not authorized to perform this action.');
    }

    public function listForParticipant(User $user, int $perPage): LengthAwarePaginator
    {
        $this->assertParticipantRole($user);

        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->with(['business', 'ambassador'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function showForParticipant(User $user, Conversation $conversation): Conversation
    {
        $this->assertParticipant($user, $conversation);

        return $conversation->load(['business', 'ambassador']);
    }

    public function listMessagesForParticipant(User $user, Conversation $conversation, int $perPage): LengthAwarePaginator
    {
        $this->assertParticipant($user, $conversation);

        return $conversation->messages()
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function send(User $user, Conversation $conversation, string $content): Message
    {
        $this->assertParticipant($user, $conversation);

        $message = DB::transaction(function () use ($user, $conversation, $content): Message {
            $message = new Message;
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->type = MessageType::Text;
            $message->content = $content;
            $message->save();

            $conversation->touch();

            return $message;
        });

        try {
            $this->messageCreatedBroadcaster->publish($message);
        } catch (Throwable $exception) {
            Log::warning('Chat message broadcast failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'exception' => $exception::class,
            ]);
        }

        return $message;
    }

    public function userCanSubscribe(User $user, int $conversationId): bool
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            return false;
        }

        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null) {
            return false;
        }

        return $conversation->hasParticipant($user);
    }

    public function markRead(User $user, Conversation $conversation): int
    {
        $this->assertParticipant($user, $conversation);

        return $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function report(User $user, Conversation $conversation, string $reason): Conversation
    {
        $this->assertParticipant($user, $conversation);

        if ($conversation->isReported()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This conversation has already been reported.',
                409,
            ));
        }

        $conversation->reported_at = now();
        $conversation->reported_by = $user->id;
        $conversation->report_reason = $reason;
        $conversation->save();

        return $conversation->fresh(['business', 'ambassador']);
    }

    public function listReportedForAdmin(User $admin, int $perPage): LengthAwarePaginator
    {
        $this->authorization->assert($admin, AdminPermission::ConversationsModerate);

        return Conversation::query()
            ->whereNotNull('reported_at')
            ->with(['business', 'ambassador', 'reporter'])
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function showReportedForAdmin(User $admin, Conversation $conversation): Conversation
    {
        $this->authorization->assert($admin, AdminPermission::ConversationsModerate);
        $this->assertReported($conversation);

        Log::info('Reported conversation accessed', [
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
        ]);

        return $conversation->load(['business', 'ambassador', 'reporter']);
    }

    public function listReportedMessagesForAdmin(User $admin, Conversation $conversation, int $perPage): LengthAwarePaginator
    {
        $this->authorization->assert($admin, AdminPermission::ConversationsModerate);
        $this->assertReported($conversation);

        Log::info('Reported conversation messages accessed', [
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
        ]);

        return $conversation->messages()
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Conversation, 1: bool}
     */
    private function openAsAmbassador(User $ambassador, array $attributes): array
    {
        if (array_key_exists('campaign_id', $attributes) && $attributes['campaign_id'] !== null) {
            $business = $this->requireBusinessFromDiscoverableCampaign((int) $attributes['campaign_id']);

            return $this->firstOrCreate($business, $ambassador);
        }

        $business = $this->requireBusiness((int) $attributes['business_id']);

        return $this->firstOrCreate($business, $ambassador);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Conversation, 1: bool}
     */
    private function openAsBusiness(User $business, array $attributes): array
    {
        $ambassador = $this->requireAmbassador((int) $attributes['ambassador_id']);

        return $this->firstOrCreate($business, $ambassador);
    }

    /**
     * @return array{0: Conversation, 1: bool}
     */
    private function firstOrCreate(User $business, User $ambassador): array
    {
        return DB::transaction(function () use ($business, $ambassador): array {
            $existing = Conversation::query()
                ->where('business_user_id', $business->id)
                ->where('ambassador_user_id', $ambassador->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [$this->withParties($existing), false];
            }

            try {
                $conversation = new Conversation;
                $conversation->business_user_id = $business->id;
                $conversation->ambassador_user_id = $ambassador->id;
                $conversation->save();

                $this->attachParticipant($conversation, $business->id);
                $this->attachParticipant($conversation, $ambassador->id);

                return [$this->withParties($conversation), true];
            } catch (UniqueConstraintViolationException) {
                $existing = Conversation::query()
                    ->where('business_user_id', $business->id)
                    ->where('ambassador_user_id', $ambassador->id)
                    ->firstOrFail();

                return [$this->withParties($existing), false];
            }
        });
    }

    private function withParties(Conversation $conversation): Conversation
    {
        return $conversation->load(['business', 'ambassador']);
    }

    private function requireBusiness(int $businessId): User
    {
        $business = User::query()
            ->whereKey($businessId)
            ->where('role', Role::Business)
            ->first();

        if ($business === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'The given data was invalid.',
                400,
                ['business_id' => ['The selected business is invalid.']],
            ));
        }

        return $business;
    }

    /**
     * Resolve the Business owner from a marketplace-discoverable Campaign.
     * Campaign is entry context only — conversations remain Business↔Ambassador pairs.
     */
    private function requireBusinessFromDiscoverableCampaign(int $campaignId): User
    {
        $campaign = Campaign::query()
            ->discoverable()
            ->with('user')
            ->whereKey($campaignId)
            ->first();

        if ($campaign === null || $campaign->user === null || ! $campaign->user->isBusiness()) {
            throw new ModelNotFoundException;
        }

        return $campaign->user;
    }

    private function requireAmbassador(int $ambassadorId): User
    {
        $ambassador = User::query()
            ->whereKey($ambassadorId)
            ->where('role', Role::Ambassador)
            ->first();

        if ($ambassador === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'The given data was invalid.',
                400,
                ['ambassador_id' => ['The selected ambassador is invalid.']],
            ));
        }

        return $ambassador;
    }

    private function attachParticipant(Conversation $conversation, int $userId): void
    {
        $participant = new ConversationParticipant;
        $participant->conversation_id = $conversation->id;
        $participant->user_id = $userId;
        $participant->save();
    }

    private function assertParticipantRole(User $user): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertParticipant(User $user, Conversation $conversation): void
    {
        $this->assertParticipantRole($user);

        if (! $conversation->hasParticipant($user)) {
            throw new ModelNotFoundException;
        }
    }

    private function assertReported(Conversation $conversation): void
    {
        if (! $conversation->isReported()) {
            throw new ModelNotFoundException;
        }
    }
}
