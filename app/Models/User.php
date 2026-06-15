<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
