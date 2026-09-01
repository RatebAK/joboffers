<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Major extends Model
{
    protected $collection = 'majors';

    protected $fillable = ['name'];
}
