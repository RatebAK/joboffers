<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AuditLog extends Model
{
    protected $collection = 'audit_logs';

    // Append-only — no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'action',       // employer_approved | employer_rejected | broadcast_sent | cv_reanalysis_triggered | bulk_employer_onboarded
        'actor_id',     // admin user _id
        'actor_name',   // denormalised name
        'target_id',    // nullable target entity id
        'target_type',  // nullable: "User" | "Employer"
        'metadata',     // arbitrary extra context array
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];
}
