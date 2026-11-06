<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Mail\PasswordResetMail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin IdeHelperUser
 *
 * @property int $user_id
 * @property string $email
 * @property string $username
 * @property string $password
 * @property string $role
 * @property bool $two_factor_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StaffUser|null $companyProfile
 * @property-read int $id
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read PassengerUser|null $passengerProfile
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUsername($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'two_factor_enabled',
        'fcm_token',
        // Batch 46: an Operator turned this crew member off (null = active); the first-sign-in password offer was answered.
        'deactivated_at',
        'password_prompted_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            // 2026-11-05: passenger account deletion (AccountDeletionService), stored in UTC.
            'deletion_requested_at' => UtcDateTime::class,
            'anonymized_at' => UtcDateTime::class,
            'deactivated_at' => 'datetime',
            'password_prompted_at' => 'datetime',
        ];
    }

    // Accessor kept — makes Sanctum and any third-party packages that
    // expect ->id work correctly with the custom PK column
    public function getIdAttribute(): int
    {
        return $this->user_id;
    }

    /**
     * The password reset email. Laravel's own notification links to a named route this API does not have, so the
     * link goes to the frontend reset page instead: the passenger page for a passenger, the staff page for staff,
     * with the token and email filled in. With MAIL_INTERCEPT_EMAIL set (Resend's free sender only reaches its
     * owner) the mail goes there and says which account it is for. A send that fails is logged and swallowed, so the
     * request answers the same whether or not the email exists or the mail could be sent. The link is never logged.
     */
    public function sendPasswordResetNotification($token): void
    {
        $staffRoles = ['driver', 'conductor', 'operator', 'admin'];
        $path = in_array($this->role, $staffRoles, true) ? '/employee/reset-password' : '/passenger/reset-password';
        $url = rtrim((string) config('app.frontend_url'), '/').$path.'?'.http_build_query(['token' => $token, 'email' => $this->email]);

        $intercept = config('mail.intercept_email');
        $recipient = $intercept ?: $this->email;
        $name = $this->username ?? explode('@', (string) $this->email)[0];

        try {
            Mail::to($recipient)->send(new PasswordResetMail($name, $url, (int) config('auth.passwords.users.expire', 60), (string) $this->email, (bool) $intercept));
        } catch (\Throwable $e) {
            Log::warning('Password reset email could not be sent for user '.$this->user_id.': '.$e->getMessage());
        }
    }

    public function passengerProfile(): HasOne
    {
        return $this->hasOne(PassengerUser::class, 'user_id', 'user_id');
    }

    public function companyProfile(): HasOne
    {
        return $this->hasOne(StaffUser::class, 'user_id', 'user_id');
    }
}
