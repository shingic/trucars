<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealActivity extends Model
{
    protected $fillable = ['deal_id', 'kind', 'direction', 'body', 'author_name'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
