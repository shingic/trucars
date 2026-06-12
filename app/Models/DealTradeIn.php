<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealTradeIn extends Model
{
    protected $fillable = [
        'deal_id', 'model_year', 'make', 'model', 'trim',
        'kilometres', 'condition', 'lien_owing_in_cents', 'customer_notes',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->model_year . ' ' . $this->make . ' ' . $this->model . ' ' . ($this->trim ?? ''));
    }
}
