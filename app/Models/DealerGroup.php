<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization that owns one or more selling dealers. Being in a group
 * grants nothing by itself: staff still see only their own dealer's cars and
 * deals until group-level permissions exist.
 */
class DealerGroup extends Model
{
    protected $fillable = ['name'];

    public function dealers(): HasMany
    {
        return $this->hasMany(Dealer::class);
    }
}
