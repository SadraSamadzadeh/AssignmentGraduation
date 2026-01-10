<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConnectedAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_username',
        'provider_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_primary',
        'status',
        'last_synced_at',
        'metadata'
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_primary' => 'boolean',
        'metadata' => 'array'
    ];

    protected $hidden = [
        'access_token',
        'refresh_token'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired()
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isPrimary()
    {
        return $this->is_primary;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}
