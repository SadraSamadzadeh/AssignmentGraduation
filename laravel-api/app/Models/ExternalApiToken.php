<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalApiToken extends Model
{
    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Check if access token is expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return now()->isAfter($this->expires_at);
    }
}
