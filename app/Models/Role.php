<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Role extends Model
{
    protected $collection = 'roles';

    protected $fillable = ['name'];
}
