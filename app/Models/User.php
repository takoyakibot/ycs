<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $api_key
 * @property string|null $google_id
 * @property array|null $google_token
 * @property string|null $google_refresh_token
 * @property string|null $avatar
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    protected $fillable = [
        'name',
        'email',
        'password',
        'api_key',
        'google_id',
        'google_token',
        'google_refresh_token',
        'avatar',
        'role',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
        'google_token',
        'google_refresh_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'api_key' => 'encrypted',
        'google_token' => 'encrypted:array',  // 配列として暗号化
        'google_refresh_token' => 'encrypted',
    ];

    public function channels()
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * スーパー管理者かどうか判定
     * 環境変数で指定されたメールアドレスの場合はtrue
     */
    public function isSuperAdmin(): bool
    {
        $superAdminEmail = config('auth.super_admin_email');

        if ($superAdminEmail && $this->email === $superAdminEmail) {
            return true;
        }

        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * 管理者（Channel Admin）かどうか判定
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->role === self::ROLE_ADMIN;
    }

    /**
     * 管理機能にアクセスできるかどうか判定
     */
    public function canAccessManage(): bool
    {
        return $this->isAdmin();
    }

    /**
     * スーパー管理者専用機能にアクセスできるかどうか判定
     */
    public function canAccessSuperAdminFeatures(): bool
    {
        return $this->isSuperAdmin();
    }
}
