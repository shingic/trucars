<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'dealer_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The dealer this user belongs to. Null for marketplace buyers; set for
     * dealer staff (F&I, GM) whose access is scoped by DealerScope.
     */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /**
     * Reservations this buyer has placed across the marketplace. These are the
     * cars shown in My Garage. Not dealer-scoped — a buyer can hold deals at
     * more than one dealership, so DealerScope is dropped on this side.
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class)->withoutGlobalScope(\App\Models\Scopes\DealerScope::class);
    }

    /**
     * Vehicles this buyer has saved to their favourites.
     *
     * Buyer-owned and cross-dealership — a buyer saves cars from any rooftop —
     * so there is deliberately no DealerScope here, the same stance taken on
     * deals(). Dealer staff never favourite; the consumer surfaces gate the
     * heart on a null dealer_id, and the toggle actions guard on it too.
     *
     * Ordered newest-save-first so the Saved cars page reads most-recent down.
     */
    public function favouriteVehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'favourites')
            ->withTimestamps()
            ->orderByDesc('favourites.created_at');
    }

    /**
     * Whether this buyer has already saved a given car. Accepts either a
     * Vehicle or a raw id, so the SRP (which holds ids in its card loop) and the
     * VDP (which holds the full model) can both ask without converting first.
     */
    public function hasFavourited(Vehicle|int $vehicle): bool
    {
        $vehicleId = $vehicle instanceof Vehicle ? $vehicle->id : $vehicle;

        return $this->favouriteVehicles()
            ->where('vehicles.id', $vehicleId)
            ->exists();
    }

    /**
     * How many cars this buyer has saved, for the header badge. Cached on the
     * instance so the header can read it in more than one place — the avatar
     * badge and the account menu — off a single count query per request. The
     * live-updating badge itself is driven by an event the toggle actions
     * dispatch; this accessor supplies the first-paint value.
     */
    protected function favouriteVehiclesCount(): Attribute
    {
        return Attribute::get(fn (): int => $this->favouriteVehicles()->count())
            ->shouldCache();
    }

    /** "Shingi" out of "Shingi Chiwocha" — for friendly greetings in My Garage. */
    protected function firstName(): Attribute
    {
        return Attribute::get(function (): string {
            $nameParts = preg_split('/\s+/', trim($this->name), 2);

            return $nameParts[0] ?? $this->name;
        });
    }

    /** "SC" out of "Shingi Chiwocha" — for the garage avatar chip. */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $nameParts = preg_split('/\s+/', trim($this->name));
            $firstInitial = strtoupper(substr($nameParts[0] ?? '', 0, 1));
            $lastInitial = count($nameParts) > 1 ? strtoupper(substr(end($nameParts), 0, 1)) : '';

            return ($firstInitial . $lastInitial) !== '' ? $firstInitial . $lastInitial : '?';
        });
    }
}
