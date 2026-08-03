<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Citizenship extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $guarded = [];
}
