<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class GoogleOAuthToken extends Model
{
    protected $collection = 'google_oauth_tokens';

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'is_valid',
    ];

    protected $casts = [
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'token_expires_at' => 'datetime',
        'is_valid'         => 'boolean',
        'scopes'           => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
