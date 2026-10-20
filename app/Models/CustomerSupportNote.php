<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An internal note TruCars support keeps on a customer account. Never customer- or dealer-facing. */
class CustomerSupportNote extends Model
{
    protected $fillable = ['customer_user_id', 'author_user_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
