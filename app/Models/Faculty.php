<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Faculty extends Model
{
    protected $collection = 'faculties';

    protected $fillable = ['name'];
}
