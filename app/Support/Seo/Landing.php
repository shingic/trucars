<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * One search landing page: "Used Honda Civic for Sale", "Used SUVs for Sale",
 * "Used Cars Under $20,000", "Used Cars for Sale in Chilliwack, BC". Each is
 * the shop with a fixed filter, at its own clean URL, with its own title and
 * description.
 */
final class Landing
{
    /**
     * @param  array{make?: string, model?: string, body?: string, fuel?: string, priceMax?: int, city?: string, province?: string}  $filters
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subject,   // "Honda Civic", "SUVs", "Hybrid Cars", "Cars Under $20,000", "Cars"
        public readonly array $filters,
        public readonly string $path,      // "/used-cars/honda/civic"
        public readonly ?string $place = null, // "Chilliwack, BC" for a city page
    ) {}

    /** What the headline counts: "Honda Cars", "Honda Civics", "SUVs", "Cars Under $20,000". */
    public function headingSubject(): string
    {
        return match ($this->type) {
            'make'  => $this->subject . ' Cars',
            'model' => self::plural($this->subject),
            default => $this->subject,
        };
    }

    /** "SUV" → "SUVs", "Honda CR-V" → "Honda CR-Vs", "Sedan" → "Sedans". All-caps words just take an "s". */
    public static function plural(string $words): string
    {
        $parts = explode(' ', $words);
        $last = array_pop($parts);
        $last = preg_match('/[a-z]/', $last) ? \Illuminate\Support\Str::plural($last) : $last . 's';

        return implode(' ', [...$parts, $last]);
    }

    /** "Used Honda Civic for Sale", the phrase people search. */
    public function linkText(): string
    {
        return match ($this->type) {
            'price' => 'Used ' . $this->subject,
            'city'  => 'Used Cars for Sale in ' . $this->place,
            default => 'Used ' . $this->subject . ' for Sale',
        };
    }
}
