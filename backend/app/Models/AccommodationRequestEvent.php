<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationRequestEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function accommodationRequest(): BelongsTo
    {
        return $this->belongsTo(AccommodationRequest::class);
    }
}
