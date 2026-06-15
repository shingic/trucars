<?php

namespace App\Models;

use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dealer extends Model
{
    protected $fillable = [
        'name',
        'city',
        'omvic_number',
    ];

    /**
     * Every car this dealership keeps in its back room.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * This dealership's fee schedule, in display order — both the 'included'
     * fees (inside the all-in price) and the 'pass_through' charges (added at
     * delivery), active or not.
     *
     * DealerScope is stripped here on purpose. The relation is already bound to
     * this dealer by its foreign key, so the scope would be redundant for the
     * console — and harmful for the consumer checkout, where the signed-in buyer
     * has no dealer_id and the scope would otherwise filter every fee out. The
     * scope still guards top-level DealerFee queries (e.g. editing a fee by id
     * in settings), which is where cross-dealer protection actually matters.
     */
    public function fees(): HasMany
    {
        return $this->hasMany(DealerFee::class)
            ->withoutGlobalScope(DealerScope::class)
            ->orderBy('sort_order');
    }

    /**
     * Only the switched-on fees, in display order — exactly what the checkout
     * breakdown shows and what gets frozen onto a deal at reserve.
     */
    protected function activeFees(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fees->where('is_active', true)->values(),
        );
    }
}
