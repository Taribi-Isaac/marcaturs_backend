<?php

namespace App\Services\Auth;

use App\Enums\AdminPermission;
use App\Enums\RegistrationApprovalEventAction;
use App\Enums\RegistrationApprovalStatus;
use App\Enums\Role;
use App\Models\RegistrationApproval;
use App\Models\RegistrationApprovalEvent;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Pre-approved email + role allow-list for participant registration (MH-OPS-031).
 *
 * Approvals are single-use: pending → consumed (by a registration) or pending → revoked.
 */
class RegistrationApprovalService
{
    public const NOT_APPROVED_MESSAGE = 'Registration is currently limited to approved participants. This email address is not approved for the selected account type.';

    public function __construct(
        private readonly AdminAuthorization $authorization,
    ) {}

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @return LengthAwarePaginator<int, RegistrationApproval>
     */
    public function list(User $actor, ?RegistrationApprovalStatus $status, ?Role $role, ?string $search, int $perPage): LengthAwarePaginator
    {
        $this->authorization->assert($actor, AdminPermission::RegistrationApprovalsManage);

        $query = RegistrationApproval::query()
            ->with(['createdBy:id,name', 'revokedBy:id,name'])
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeEmail($search)).'%';
            $query->where('email', 'like', $term);
        }

        return $query->paginate($perPage);
    }

    public function show(User $actor, int $approvalId): RegistrationApproval
    {
        $this->authorization->assert($actor, AdminPermission::RegistrationApprovalsManage);

        $approval = RegistrationApproval::query()->find($approvalId);
        if ($approval === null) {
            throw (new ModelNotFoundException)->setModel(RegistrationApproval::class, [$approvalId]);
        }

        $approval->load([
            'createdBy:id,name',
            'revokedBy:id,name',
            'events' => fn ($query) => $query->orderByDesc('id')->limit(50),
            'events.actor:id,name',
        ]);

        return $approval;
    }

    public function create(User $actor, string $email, Role $role, ?string $note): RegistrationApproval
    {
        $this->authorization->assert($actor, AdminPermission::RegistrationApprovalsManage);

        if (! in_array($role->value, Role::publicRegistrationValues(), true)) {
            throw ValidationException::withMessages([
                'role' => ['The selected role is invalid.'],
            ]);
        }

        $email = self::normalizeEmail($email);
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        try {
            return DB::transaction(function () use ($actor, $email, $role, $note): RegistrationApproval {
                if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['An account with this email already exists.'],
                    ]);
                }

                if (RegistrationApproval::query()->where('active_email', $email)->lockForUpdate()->exists()) {
                    $this->rejectDuplicateApproval();
                }

                $approval = new RegistrationApproval;
                $approval->email = $email;
                $approval->active_email = $email;
                $approval->role = $role;
                $approval->status = RegistrationApprovalStatus::Pending;
                $approval->note = $note;
                $approval->created_by_user_id = $actor->id;
                $approval->save();

                $this->recordEvent(
                    approval: $approval,
                    actor: $actor,
                    action: RegistrationApprovalEventAction::Created,
                    previousStatus: null,
                    newStatus: RegistrationApprovalStatus::Pending,
                    meta: ['role' => $role->value],
                );

                return $approval->fresh(['createdBy:id,name']) ?? $approval;
            });
        } catch (UniqueConstraintViolationException) {
            $this->rejectDuplicateApproval();
        }
    }

    public function revoke(User $actor, int $approvalId, ?string $reason): RegistrationApproval
    {
        $this->authorization->assert($actor, AdminPermission::RegistrationApprovalsManage);

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($actor, $approvalId, $reason): RegistrationApproval {
            $approval = RegistrationApproval::query()->lockForUpdate()->find($approvalId);
            if ($approval === null) {
                throw (new ModelNotFoundException)->setModel(RegistrationApproval::class, [$approvalId]);
            }

            if ($approval->status === RegistrationApprovalStatus::Consumed) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This approval has already been used to register an account and cannot be revoked.',
                    422,
                ));
            }

            if ($approval->status === RegistrationApprovalStatus::Revoked) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This approval has already been revoked.',
                    422,
                ));
            }

            $approval->status = RegistrationApprovalStatus::Revoked;
            $approval->active_email = null;
            $approval->revoked_at = now();
            $approval->revoked_by_user_id = $actor->id;
            $approval->save();

            $this->recordEvent(
                approval: $approval,
                actor: $actor,
                action: RegistrationApprovalEventAction::Revoked,
                previousStatus: RegistrationApprovalStatus::Pending,
                newStatus: RegistrationApprovalStatus::Revoked,
                reason: $reason,
            );

            return $approval->fresh(['createdBy:id,name', 'revokedBy:id,name']) ?? $approval;
        });
    }

    /**
     * Lock and consume the pending approval for this email + role, or reject the registration.
     *
     * Must run inside the registration transaction so a later failure (e.g. the user insert)
     * rolls the consumption back.
     */
    public function claimForRegistration(string $email, Role $role): RegistrationApproval
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Registration approvals must be claimed inside a database transaction.');
        }

        $email = self::normalizeEmail($email);

        $approval = RegistrationApproval::query()
            ->where('active_email', $email)
            ->where('status', RegistrationApprovalStatus::Pending)
            ->lockForUpdate()
            ->first();

        if ($approval === null || $approval->role !== $role) {
            $this->rejectRegistration($role);
        }

        $claimed = RegistrationApproval::query()
            ->whereKey($approval->id)
            ->where('status', RegistrationApprovalStatus::Pending)
            ->update([
                'status' => RegistrationApprovalStatus::Consumed->value,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            $this->rejectRegistration($role);
        }

        return $approval;
    }

    /**
     * Link a claimed approval to the account it created and record the audit event.
     */
    public function recordConsumption(RegistrationApproval $approval, User $user): void
    {
        RegistrationApproval::query()
            ->whereKey($approval->id)
            ->update(['consumed_by_user_id' => $user->id]);

        $this->recordEvent(
            approval: $approval,
            actor: $user,
            action: RegistrationApprovalEventAction::Consumed,
            previousStatus: RegistrationApprovalStatus::Pending,
            newStatus: RegistrationApprovalStatus::Consumed,
        );
    }

    private function rejectRegistration(Role $role): never
    {
        Log::info('registration.approval_required_rejected', [
            'event' => 'registration_rejected_not_approved',
            'requested_role' => $role->value,
        ]);

        throw new HttpResponseException(ApiResponse::error(
            ApiErrorCode::FORBIDDEN,
            self::NOT_APPROVED_MESSAGE,
            403,
        ));
    }

    private function rejectDuplicateApproval(): never
    {
        throw ValidationException::withMessages([
            'email' => ['An active registration approval already exists for this email.'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordEvent(
        RegistrationApproval $approval,
        User $actor,
        RegistrationApprovalEventAction $action,
        ?RegistrationApprovalStatus $previousStatus,
        ?RegistrationApprovalStatus $newStatus,
        ?string $reason = null,
        ?array $meta = null,
    ): void {
        $event = new RegistrationApprovalEvent;
        $event->registration_approval_id = $approval->id;
        $event->actor_user_id = $actor->id;
        $event->action = $action;
        $event->previous_status = $previousStatus;
        $event->new_status = $newStatus;
        $event->reason = $reason;
        $event->meta = $meta;
        $event->save();
    }
}
