<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationRequestMessage extends Model
{
    protected $guarded = ['id'];

    public function accommodationRequest(): BelongsTo
    {
        return $this->belongsTo(AccommodationRequest::class);
    }
}
