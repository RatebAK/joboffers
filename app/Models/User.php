<?php

namespace App\Models;
//TODO uncomment email
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use MongoDB\Laravel\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject //, MustVerifyEmail
{

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_employer',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_employer' => 'boolean',
    ];
    
    protected $hidden = ['password', 'remember_token'];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
