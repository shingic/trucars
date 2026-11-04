<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One marketing email sent to a shopper (see CheckoutRecovery). */
class MarketingEmail extends Model
{
    public $timestamps = false;

    public const KIND_LABELS = [
        'recovery_1h' => 'Pick up where you left off',
        'recovery_1d' => 'Still available + similar cars',
        'recovery_3d' => 'Refundable deposit reminder',
        'recovery_7d' => 'Recommended cars',
    ];

    protected $fillable = ['user_id', 'checkout_draft_id', 'kind', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function label(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
