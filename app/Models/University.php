<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class University extends Model
{
    protected $collection = 'universities';

    protected $fillable = ['name'];
}
