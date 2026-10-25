<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resume extends Model
{
    protected $attributes = ['version' => 1];

    protected $fillable = ['user_id', 'title', 'template', 'content', 'version'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
