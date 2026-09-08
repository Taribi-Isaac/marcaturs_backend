<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => AccountStatus::class,
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    public function isBusiness(): bool
    {
        return $this->hasRole(Role::Business);
    }

    public function isAmbassador(): bool
    {
        return $this->hasRole(Role::Ambassador);
    }

    /**
     * @return HasOne<BusinessProfile, $this>
     */
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /**
     * @return HasOne<AmbassadorProfile, $this>
     */
    public function ambassadorProfile(): HasOne
    {
        return $this->hasOne(AmbassadorProfile::class);
    }

    /**
     * @return HasMany<VerificationSubmission, $this>
     */
    public function verificationSubmissions(): HasMany
    {
        return $this->hasMany(VerificationSubmission::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<UserStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(UserStatusEvent::class, 'target_user_id')->orderBy('id');
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function conversationParticipations(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function ambassadorDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'ambassador_user_id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function businessDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'business_user_id');
    }

    /**
     * @return HasMany<Commission, $this>
     */
    public function ambassadorCommissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'ambassador_user_id');
    }

    /**
     * @return HasMany<Commission, $this>
     */
    public function businessCommissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'business_user_id');
    }
}
