<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CarriesCampaignAttribution;
use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An enquiry about a car. Fenced to the signed-in dealer like deals are. */
#[ScopedBy(DealerScope::class)]
class Lead extends Model
{
    use CarriesCampaignAttribution;

    protected $fillable = ['vehicle_id', 'dealer_id', 'campaign_id', 'attribution', 'name', 'email', 'phone', 'message', 'status', 'topic'];

    /** What the contact form asks: "What can we help you with?" */
    public const TOPICS = [
        'general'     => 'General (how Trumo works, the deposit)',
        'car'         => 'A car on Trumo',
        'reservation' => 'My reservation',
        'trade_in'    => 'Trading in my vehicle',
        'financing'   => 'Financing',
        'dealer'      => 'I\'m a dealership and want to sell on Trumo',
        'technical'   => 'Problem with the website',
    ];

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
