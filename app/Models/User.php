<?php

// torsade/app/Models/User.php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property bool $is_active
 * @property bool $must_change_password
 * @property bool $two_factor_required
 * @property CarbonInterface|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// The role, the status, the forced password change and the two-factor
// requirement are never mass assignable: they are only set explicitly by
// App\Services\UserAccounts.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => Role::Reader->value,
        'is_active' => true,
        'must_change_password' => false,
        'two_factor_required' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_required' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Whether the user may write the network data: editors and administrators.
     */
    public function canEditNetwork(): bool
    {
        return in_array($this->role, [Role::Editor, Role::Admin], true);
    }

    /**
     * Whether an administrator requires two-factor authentication on this
     * account and the user has not finished configuring it yet.
     */
    public function mustSetUpTwoFactor(): bool
    {
        return $this->two_factor_required && ! $this->hasEnabledTwoFactorAuthentication();
    }

    /**
     * The state shown to administrators: enabled, required but still to be
     * configured, or disabled.
     *
     * @return 'enabled'|'required'|'disabled'
     */
    public function twoFactorState(): string
    {
        return match (true) {
            $this->hasEnabledTwoFactorAuthentication() => 'enabled',
            $this->two_factor_required => 'required',
            default => 'disabled',
        };
    }
}
