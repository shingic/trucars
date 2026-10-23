<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CleansText;
use App\Models\Concerns\HasStreetAddress;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One physical store of a selling dealer: its address, timezone, contact and
 * feed id. Every car sits at exactly one rooftop; the dealer stays the seller.
 *
 * The primary rooftop mirrors the dealer's own address columns (see
 * Dealer::syncPrimaryRooftop), so the dealer profile keeps editing it. Any
 * further rooftops are edited on their own.
 */
class Rooftop extends Model
{
    use CleansText;
    use HasStreetAddress;

    /** Text columns kept as clean UTF-8 (see CleansText). */
    public const CLEAN_TEXT = ['name', 'street_address', 'unit', 'city', 'phone', 'email'];

    /** What a rooftop holds that the dealer's own columns also hold. */
    public const LOCATION_FIELDS = [
        'street_address', 'unit', 'city', 'province', 'postal_code', 'country',
        'latitude', 'longitude', 'timezone', 'phone', 'email',
    ];

    protected $fillable = [
        'dealer_id', 'name', 'is_primary', 'feed_rooftop_id', 'is_active',
        'street_address', 'unit', 'city', 'province', 'postal_code', 'country',
        'latitude', 'longitude', 'timezone', 'phone', 'email', 'hours', 'pickup_points',
    ];

    /** Keys of the hours column, Monday first. */
    public const WEEKDAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    protected $attributes = [
        'is_primary' => false,
        'is_active'  => true,
    ];

    protected function casts(): array
    {
        return [
            'latitude'      => 'float',
            'longitude'     => 'float',
            'is_primary'    => 'boolean',
            'is_active'     => 'boolean',
            'hours'         => 'array',
            'pickup_points' => 'array',
        ];
    }

    /** Whether opening hours have been entered. Without them nothing is filtered by hours. */
    public function hasHours(): bool
    {
        return is_array($this->hours) && $this->hours !== [];
    }

    /**
     * ['09:00', '18:00'] on that day in the store's timezone, or null when
     * it's closed. Only meaningful when hasHours().
     *
     * @return array{0: string, 1: string}|null
     */
    public function hoursOn(Carbon $day): ?array
    {
        $key = strtolower($day->copy()->setTimezone($this->localTimezone())->format('D'));
        $hours = $this->hours[$key] ?? null;

        return is_array($hours) && count($hours) === 2 ? array_values($hours) : null;
    }

    /** "Open today 9am – 6pm" / "Closed today", or null when hours aren't known. */
    public function todayLabel(): ?string
    {
        if (! $this->hasHours()) {
            return null;
        }

        $hours = $this->hoursOn(now($this->localTimezone()));
        $time = fn (string $clock) => Carbon::createFromFormat('H:i', $clock)->format(str_ends_with($clock, ':00') ? 'ga' : 'g:ia');

        return $hours === null ? 'Closed today' : 'Open today ' . $time($hours[0]) . ' – ' . $time($hours[1]);
    }

    /**
     * Other places a buyer can collect a car from this store.
     *
     * @return array<int, array{name: string, address: string}>
     */
    public function pickupPoints(): array
    {
        return array_values(array_filter($this->pickup_points ?? [], fn ($point) => filled($point['name'] ?? null)));
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** The store's own name, or the dealer's for the primary rooftop. */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->name ?: (string) $this->dealer?->name);
    }

    /**
     * The store as it stands right now, frozen onto a reservation beside the
     * seller snapshot so later edits never rewrite the buyer's record.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return ['id' => $this->id, 'name' => $this->display_name]
            + $this->only(self::LOCATION_FIELDS)
            + ['captured_at' => now()->toIso8601String()];
    }
}
