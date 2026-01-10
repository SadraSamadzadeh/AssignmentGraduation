<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the connected accounts for the user
     */
    public function connectedAccounts()
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    /**
     * Get active connected accounts
     */
    public function activeConnectedAccounts()
    {
        return $this->hasMany(ConnectedAccount::class)->where('status', 'active');
    }

    /**
     * Get primary connected account
     */
    public function primaryConnectedAccount()
    {
        return $this->hasOne(ConnectedAccount::class)->where('is_primary', true)->where('status', 'active');
    }

    /**
     * Check if user has connected a specific provider
     */
    public function hasConnectedProvider(string $provider)
    {
        return $this->connectedAccounts()
            ->where('provider', $provider)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'auth_system',
        'external_user_id',
        'external_credentials',
        'external_token_expires_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'external_credentials' => 'array',
        'external_token_expires_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get the global matches created by this user.
     */
    public function createdMatches()
    {
        return $this->hasMany(GlobalMatches::class, 'created_by_user_id');
    }

    /**
     * Get the tracking records assigned to this user.
     */
    public function assignedTrackingRecords()
    {
        return $this->hasMany(TrackingDashboard::class, 'assigned_to_user_id');
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is a viewer.
     */
    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
}
