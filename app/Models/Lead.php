<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CarriesCampaignAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use CarriesCampaignAttribution;

    protected $fillable = ['vehicle_id', 'dealer_id', 'campaign_id', 'attribution', 'name', 'email', 'phone', 'message', 'status'];

    protected $attributes = [
        'status' => 'new',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }
}
