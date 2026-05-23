<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Employer extends Model
{
    protected $primaryKey = 'user_id';
            
    protected $fillable = [
        '_id',
        'user_id',
        'document_path',
        'document_name',
        'status',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
