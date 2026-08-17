<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Meeting extends Model
{
    protected $collection = 'meetings';

    protected $fillable = [
        'organizer_id',
        'invitee_id',
        'title',
        'meeting_type',
        'proposed_date',
        'proposed_start_time',
        'proposed_duration_minutes',
        'status',
        'location_or_link',
        'meet_link',
        'google_calendar_event_id',
        'decline_reason',
        'cancellation_reason',
        'cancelled_by',
        'notes',
        'previous_schedules',
    ];

    protected $casts = [
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
        'notes'              => 'array',
        'previous_schedules' => 'array',
    ];

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function invitee()
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }
}
