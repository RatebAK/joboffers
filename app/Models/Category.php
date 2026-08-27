<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Category extends Model
{
    protected $collection = 'categories';

    protected $fillable = ['name'];
}
