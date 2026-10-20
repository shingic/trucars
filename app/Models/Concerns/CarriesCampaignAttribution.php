<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Campaign;
use App\Support\CampaignAttribution;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * For records a shopper creates (leads, reservations): when the shopping
 * session came through one of this dealer's campaign links, the campaign and
 * the touch are stamped on as the row is created. Done at the model so every
 * path that creates one — checkout today, an inquiry form later — is covered.
 * An explicitly set campaign_id is left alone.
 */
trait CarriesCampaignAttribution
{
    public static function bootCarriesCampaignAttribution(): void
    {
        static::creating(function (self $record) {
            if ($record->campaign_id !== null || $record->dealer_id === null) {
                return;
            }

            $attribution = CampaignAttribution::forDealer((int) $record->dealer_id);

            if ($attribution === null) {
                return;
            }

            $record->campaign_id = $attribution['campaign_id'];
            $record->attribution = Arr::except($attribution, ['campaign_id', 'dealer_id']);
        });
    }

    public function initializeCarriesCampaignAttribution(): void
    {
        $this->mergeCasts(['attribution' => 'array']);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
