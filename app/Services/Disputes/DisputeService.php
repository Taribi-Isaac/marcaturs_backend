<?php

namespace App\Services\Disputes;

use App\Enums\AdminPermission;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeAttachment;
use App\Models\DisputeEvent;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Services\Notifications\DisputeNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisputeService
{
    public function __construct(
        private readonly DisputeCategoryService $categories,
        private readonly DisputeAttachmentStore $attachments,
        private readonly DisputeNotificationDispatcher $notifications,
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Deal $deal, array $attributes): Dispute
    {
        $this->assertParticipantCreator($actor, $deal);

        $category = $this->categories->requireActive((int) $attributes['category_id']);
        $description = trim((string) $attributes['description']);
        $accusedId = $deal->business_user_id === $actor->id
            ? $deal->ambassador_user_id
            : $deal->business_user_id;

        $opened = false;

        $dispute = DB::transaction(function () use ($actor, $deal, $category, $description, $accusedId, &$opened): Dispute {
            $lockedDeal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if (! $lockedDeal->isParty($actor)) {
                throw new ModelNotFoundException;
            }

            $commissionId = Commission::query()
                ->where('deal_id', $lockedDeal->id)
                ->value('id');

            $dispute = new Dispute;
            $dispute->reference = $this->generateReference();
            $dispute->deal_id = $lockedDeal->id;
            $dispute->commission_id = $commissionId;
            $dispute->category_id = $category->id;
            $dispute->reporter_user_id = $actor->id;
            $dispute->accused_user_id = $accusedId;
            $dispute->description = $description;
            $dispute->status = DisputeStatus::Submitted;
            $dispute->save();

            $this->writeEvent(
                $dispute,
                $actor,
                DisputeEventType::Created,
                null,
                DisputeStatus::Submitted,
                [
                    'deal_id' => $lockedDeal->id,
                    'commission_id' => $commissionId,
                    'category_id' => $category->id,
                    'has_description' => true,
                ],
            );

            Log::info('Dispute created', [
                'dispute_id' => $dispute->id,
                'reference' => $dispute->reference,
                'deal_id' => $lockedDeal->id,
                'reporter_user_id' => $actor->id,
                'accused_user_id' => $accusedId,
                'status' => $dispute->status->value,
            ]);

            $opened = true;

            return $this->withParticipantRelations($dispute);
        });

        if ($opened) {
            $this->notifications->notifyOpened($dispute);
        }

        return $dispute;
    }

    public function listForParticipant(User $user, int $perPage = 20): LengthAwarePaginator
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        return Dispute::query()
            ->where(function ($query) use ($user): void {
                $query->where('reporter_user_id', $user->id)
                    ->orWhere('accused_user_id', $user->id);
            })
            ->with(['category', 'reporter', 'accused', 'deal'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function showForParticipant(User $user, Dispute $dispute): Dispute
    {
        $this->assertParticipantParty($user, $dispute);

        return $this->withParticipantRelations($dispute);
    }

    public function listForAdmin(?DisputeStatus $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Dispute::query()
            ->with([
                'category',
                'reporter',
                'accused',
                'deal.business',
                'deal.ambassador',
                'deal.campaign',
                'deal.campaignVersion',
                'commission',
            ])
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function showForAdmin(Dispute $dispute): Dispute
    {
        return $this->withAdminRelations($dispute);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startReview(User $admin, Dispute $dispute, array $attributes = []): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        return $this->transition(
            $admin,
            $dispute,
            DisputeStatus::Submitted,
            DisputeStatus::UnderReview,
            DisputeEventType::ReviewStarted,
            [
                'has_classification_note' => $this->optionalText($attributes['note'] ?? null) !== null,
                'classification_note' => $this->optionalText($attributes['note'] ?? null),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function requestEvidence(User $admin, Dispute $dispute, array $attributes): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        $reason = trim((string) ($attributes['reason'] ?? ''));
        if ($reason === '') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'A reason is required when requesting evidence.',
                400,
            ));
        }

        return DB::transaction(function () use ($admin, $dispute, $reason): Dispute {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [DisputeStatus::UnderReview, DisputeStatus::DecisionPending], true)) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'Evidence can only be requested from under_review or decision_pending.',
                    422,
                ));
            }

            $previous = $locked->status;
            $locked->status = DisputeStatus::EvidenceRequested;
            $locked->save();

            $this->writeEvent(
                $locked,
                $admin,
                DisputeEventType::EvidenceRequested,
                $previous,
                DisputeStatus::EvidenceRequested,
                [
                    'has_reason' => true,
                    'reason' => $reason,
                    'deal_id' => $locked->deal_id,
                ],
            );

            Log::info('Dispute transitioned', [
                'dispute_id' => $locked->id,
                'deal_id' => $locked->deal_id,
                'actor_user_id' => $admin->id,
                'previous_status' => $previous->value,
                'new_status' => $locked->status->value,
                'event_type' => DisputeEventType::EvidenceRequested->value,
            ]);

            return $this->withAdminRelations($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function resumeReview(User $admin, Dispute $dispute, array $attributes = []): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        return $this->transition(
            $admin,
            $dispute,
            DisputeStatus::EvidenceRequested,
            DisputeStatus::UnderReview,
            DisputeEventType::ReviewResumed,
            [
                'has_note' => $this->optionalText($attributes['note'] ?? null) !== null,
                'note' => $this->optionalText($attributes['note'] ?? null),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markDecisionPending(User $admin, Dispute $dispute, array $attributes = []): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        return $this->transition(
            $admin,
            $dispute,
            DisputeStatus::UnderReview,
            DisputeStatus::DecisionPending,
            DisputeEventType::DecisionPending,
            [
                'has_note' => $this->optionalText($attributes['note'] ?? null) !== null,
                'note' => $this->optionalText($attributes['note'] ?? null),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function resolve(User $admin, Dispute $dispute, array $attributes): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        $decisionNotes = trim((string) ($attributes['decision_notes'] ?? ''));
        $actionNotes = trim((string) ($attributes['action_notes'] ?? ''));

        if ($decisionNotes === '' || $actionNotes === '') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'decision_notes and action_notes are required to resolve a Dispute.',
                400,
            ));
        }

        $resolved = false;

        $updated = DB::transaction(function () use ($admin, $dispute, $decisionNotes, $actionNotes, &$resolved): Dispute {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DisputeStatus::DecisionPending) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'Only a decision_pending Dispute can be resolved.',
                    422,
                ));
            }

            $previous = $locked->status;
            $locked->status = DisputeStatus::Resolved;
            $locked->decision_notes = $decisionNotes;
            $locked->action_notes = $actionNotes;
            $locked->resolved_at = now();
            $locked->resolved_by_admin_user_id = $admin->id;
            $locked->save();

            $this->writeEvent(
                $locked,
                $admin,
                DisputeEventType::Resolved,
                $previous,
                DisputeStatus::Resolved,
                [
                    'has_decision_notes' => true,
                    'has_action_notes' => true,
                    'deal_id' => $locked->deal_id,
                ],
            );

            Log::info('Dispute resolved', [
                'dispute_id' => $locked->id,
                'deal_id' => $locked->deal_id,
                'admin_user_id' => $admin->id,
                'previous_status' => $previous->value,
                'new_status' => $locked->status->value,
            ]);

            $resolved = true;

            return $this->withAdminRelations($locked);
        });

        if ($resolved) {
            $this->notifications->notifyResolved($updated);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function close(User $admin, Dispute $dispute, array $attributes = []): Dispute
    {
        $this->authorization->assert($admin, AdminPermission::DisputesManage);

        return $this->transition(
            $admin,
            $dispute,
            DisputeStatus::Resolved,
            DisputeStatus::Closed,
            DisputeEventType::Closed,
            [
                'has_close_note' => $this->optionalText($attributes['note'] ?? null) !== null,
                'close_note' => $this->optionalText($attributes['note'] ?? null),
            ],
            function (Dispute $locked) use ($admin): void {
                $locked->closed_at = now();
                $locked->closed_by_admin_user_id = $admin->id;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function uploadAttachment(
        User $actor,
        Dispute $dispute,
        array $attributes,
        UploadedFile $file,
    ): DisputeAttachment {
        if ($actor->isAdmin()) {
            // Admin may upload during investigation regardless of party-upload window,
            // but not after terminal closed. Resolved also blocked for parties; Admin
            // investigation uploads stop at resolved/closed per Phase-1 contract.
        } elseif (! $actor->isBusiness() && ! $actor->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        $stored = $this->attachments->store($dispute, $file);

        try {
            return DB::transaction(function () use ($actor, $dispute, $attributes, $stored): DisputeAttachment {
                $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

                if ($actor->isAdmin()) {
                    if (in_array($locked->status, [DisputeStatus::Resolved, DisputeStatus::Closed], true)) {
                        throw new HttpResponseException(ApiResponse::error(
                            ApiErrorCode::BUSINESS_VALIDATION,
                            'Attachments cannot be uploaded after a Dispute is resolved or closed.',
                            422,
                        ));
                    }
                } else {
                    $this->assertParticipantParty($actor, $locked);

                    if (! $locked->status->allowsPartyAttachmentUpload()) {
                        throw new HttpResponseException(ApiResponse::error(
                            ApiErrorCode::BUSINESS_VALIDATION,
                            'Attachments cannot be uploaded in the current Dispute state.',
                            422,
                        ));
                    }
                }

                $attachment = new DisputeAttachment;
                $attachment->dispute_id = $locked->id;
                $attachment->uploader_user_id = $actor->id;
                $attachment->disk = $stored['disk'];
                $attachment->path = $stored['path'];
                $attachment->original_filename = $stored['original_filename'];
                $attachment->mime_type = $stored['mime_type'];
                $attachment->size_bytes = $stored['size_bytes'];
                $attachment->note = $this->optionalText($attributes['note'] ?? null);
                $attachment->save();

                return $attachment->loadMissing('uploader');
            });
        } catch (\Throwable $exception) {
            $this->attachments->deleteStored($stored['disk'], $stored['path']);

            throw $exception;
        }
    }

    public function streamAttachment(User $actor, Dispute $dispute, DisputeAttachment $attachment): StreamedResponse
    {
        if ($attachment->dispute_id !== $dispute->id) {
            throw new ModelNotFoundException;
        }

        if ($actor->isAdmin()) {
            // allowed
        } else {
            $this->assertParticipantParty($actor, $dispute);
        }

        if (! $attachment->hasFile()) {
            throw new ModelNotFoundException;
        }

        return $this->attachments->stream($attachment);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  (callable(Dispute): void)|null  $mutator
     */
    private function transition(
        User $admin,
        Dispute $dispute,
        DisputeStatus $expectedFrom,
        DisputeStatus $to,
        DisputeEventType $eventType,
        array $metadata = [],
        ?callable $mutator = null,
    ): Dispute {
        return DB::transaction(function () use ($admin, $dispute, $expectedFrom, $to, $eventType, $metadata, $mutator): Dispute {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $expectedFrom) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    sprintf(
                        'This Dispute cannot transition from %s to %s.',
                        $locked->status->value,
                        $to->value,
                    ),
                    422,
                ));
            }

            $previous = $locked->status;
            $locked->status = $to;

            if ($mutator !== null) {
                $mutator($locked);
            }

            $locked->save();

            $this->writeEvent(
                $locked,
                $admin,
                $eventType,
                $previous,
                $to,
                array_merge($metadata, [
                    'deal_id' => $locked->deal_id,
                ]),
            );

            Log::info('Dispute transitioned', [
                'dispute_id' => $locked->id,
                'deal_id' => $locked->deal_id,
                'actor_user_id' => $admin->id,
                'previous_status' => $previous->value,
                'new_status' => $to->value,
                'event_type' => $eventType->value,
            ]);

            return $this->withAdminRelations($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeEvent(
        Dispute $dispute,
        User $actor,
        DisputeEventType $type,
        ?DisputeStatus $previous,
        DisputeStatus $new,
        array $metadata,
    ): void {
        $event = new DisputeEvent;
        $event->dispute_id = $dispute->id;
        $event->actor_user_id = $actor->id;
        $event->type = $type;
        $event->previous_status = $previous;
        $event->new_status = $new;
        $event->metadata = $this->sanitizeEventMetadata($metadata);
        $event->save();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeEventMetadata(array $metadata): array
    {
        unset(
            $metadata['path'],
            $metadata['disk'],
            $metadata['password'],
            $metadata['token'],
            $metadata['bank_account'],
            $metadata['payment_secret'],
        );

        return $metadata;
    }

    private function generateReference(): string
    {
        do {
            $reference = 'MH-D-'.strtoupper(Str::random(8));
        } while (Dispute::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function assertParticipantCreator(User $actor, Deal $deal): void
    {
        if ($actor->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if (! $actor->isBusiness() && ! $actor->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if (! $deal->isParty($actor)) {
            throw new ModelNotFoundException;
        }
    }

    private function assertParticipantParty(User $user, Dispute $dispute): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if (! $dispute->isParty($user)) {
            throw new ModelNotFoundException;
        }
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function withParticipantRelations(Dispute $dispute): Dispute
    {
        return $dispute->loadMissing([
            'category',
            'reporter',
            'accused',
            'deal',
            'commission',
            'attachments.uploader',
            'events' => fn ($query) => $query->whereIn('type', [
                DisputeEventType::Created,
                DisputeEventType::EvidenceRequested,
                DisputeEventType::Resolved,
                DisputeEventType::Closed,
            ]),
        ]);
    }

    private function withAdminRelations(Dispute $dispute): Dispute
    {
        $loaded = $dispute->loadMissing([
            'category',
            'reporter',
            'accused',
            'resolvedByAdmin',
            'closedByAdmin',
            'deal.business',
            'deal.ambassador',
            'deal.campaign',
            'deal.campaignVersion',
            'deal.paymentEvidences',
            'deal.events.actor',
            'commission',
            'attachments.uploader',
            'events.actor',
        ]);

        if ($loaded->deal !== null) {
            $loaded->deal->loadCount([
                'disputes as open_dispute_count' => fn ($disputes) => $disputes->open(),
            ]);
        }

        return $loaded;
    }
}
