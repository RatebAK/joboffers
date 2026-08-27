<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class City extends Model
{
    protected $collection = 'cities';

    protected $fillable = ['name'];
}
