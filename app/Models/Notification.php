<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Notification extends Model
{
    protected $collection = 'notifications';

    protected $fillable = [
        'user_id',
        'type',                 // application_status_changed | direct_offer_received | employer_decision | broadcast | new_application
        'message',
        'related_entity_id',
        'related_entity_type',  // Application | DirectOffer | Employer
        'read_at',
    ];

    protected $casts = [
        'user_id'    => 'string',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Guarantee user_id is always persisted as a plain string,
        // regardless of whether the caller passed an ObjectId or string.
        static::creating(function (self $notification) {
            if ($notification->user_id !== null) {
                $notification->user_id = (string) $notification->user_id;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
