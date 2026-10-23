<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dealership's choice about one TruCars default protection product:
 * whether buyers see it at that dealership, and the dealership's own price.
 * No row means the default is shown at the default price.
 */
class DealerProtectionSetting extends Model
{
    protected $fillable = ['dealer_id', 'protection_product_id', 'is_enabled', 'amount_in_cents'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return [
            'is_enabled'      => 'boolean',
            'amount_in_cents' => 'integer',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProtectionProduct::class, 'protection_product_id');
    }
}
