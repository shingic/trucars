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

        // Questionnaire (self-reported by the customer in checkout)
        'exterior_colour', 'key_count', 'features',
        'exterior_condition', 'interior_condition', 'tire_condition', 'mechanical_condition',
        'accident_history', 'owner_count', 'title_status',
        'was_smoked_in', 'carried_pets', 'has_aftermarket_mods',

        // Saved estimate (preliminary and non-binding — the dealership's
        // inspection sets the final value; this is only the anchor)
        'estimated_value_in_cents', 'estimated_value_low_in_cents', 'estimated_value_high_in_cents',
        'valuation_breakdown', 'valuation_provider', 'valuated_at', 'estimate_is_binding',
    ];

    protected function casts(): array
    {
        return [
            'features'             => 'array',
            'valuation_breakdown'  => 'array',
            'valuated_at'          => 'datetime',
            'was_smoked_in'        => 'boolean',
            'carried_pets'         => 'boolean',
            'has_aftermarket_mods' => 'boolean',
            'estimate_is_binding'  => 'boolean',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->model_year . ' ' . $this->make . ' ' . $this->model . ' ' . ($this->trim ?? ''));
    }

    public function hasEstimate(): bool
    {
        return $this->estimated_value_low_in_cents !== null
            && $this->estimated_value_high_in_cents !== null;
    }

    public function getEstimatedRangeLabelAttribute(): ?string
    {
        if (! $this->hasEstimate()) {
            return null;
        }

        return '$' . number_format($this->estimated_value_low_in_cents / 100, 0)
            . ' – $' . number_format($this->estimated_value_high_in_cents / 100, 0);
    }
}
