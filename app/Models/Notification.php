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
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
