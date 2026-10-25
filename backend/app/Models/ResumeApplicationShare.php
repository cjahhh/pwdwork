<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResumeApplicationShare extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'shared_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
