<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Email\Catalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email the platform sent — written by App\Listeners\LogSentEmail. */
class EmailLog extends Model
{
    protected $table = 'email_log';

    public $timestamps = false;

    protected $fillable = ['type', 'user_id', 'deal_id', 'to_email', 'subject', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return Catalogue::ALL[$this->type]['name'] ?? $this->type;
    }
}
