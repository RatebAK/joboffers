<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class DirectOffer extends Model
{
    protected $collection = 'direct_offers';

    protected $fillable = [
        'employer_id',
        'job_seeker_id',
        'job_post_id',
        'message',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function employer()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function jobSeeker()
    {
        return $this->belongsTo(User::class, 'job_seeker_id');
    }

    public function jobPost()
    {
        return $this->belongsTo(JobPost::class, 'job_post_id');
    }
}
