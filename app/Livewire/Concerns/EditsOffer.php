<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Offer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The offer form (Settings › Brands › Offers): its fields, validation, and the
 * preview model rendered with partials.offer-card. The brand isn't a field —
 * it's the brand being edited; $make only carries its name for display.
 */
trait EditsOffer
{
    public string $type = Offer::TYPE_APR;
    public string $title = 'Special CPO financing';
    public string $make = '';
    public string $models = '';
    public ?string $yearMin = null;
    public ?string $yearMax = null;
    /** @var array<int, string> */
    public array $provinces = [];
    public bool $certifiedOnly = true;
    public ?string $apr = null;
    public ?string $termMonths = null;
    public string $lender = '';
    public ?string $cashAmount = null;
    public string $benefit = '';
    public string $conditions = '';
    public ?string $startsOn = null;
    public ?string $endsOn = null;
    public bool $combinable = false;

    protected function fillOfferForm(Offer $offer): void
    {
        $this->type = $offer->type;
        $this->title = $offer->title;
        $this->models = implode(', ', $offer->models ?? []);
        $this->yearMin = $offer->year_min ? (string) $offer->year_min : null;
        $this->yearMax = $offer->year_max ? (string) $offer->year_max : null;
        $this->provinces = $offer->provinces ?? [];
        $this->certifiedOnly = $offer->certified_only;
        $this->apr = $offer->apr !== null ? (string) (float) $offer->apr : null;
        $this->termMonths = $offer->term_months ? (string) $offer->term_months : null;
        $this->lender = (string) $offer->lender;
        $this->cashAmount = $offer->cash_amount_in_cents !== null ? (string) ($offer->cash_amount_in_cents / 100) : null;
        $this->benefit = (string) $offer->benefit;
        $this->conditions = $offer->conditions;
        $this->startsOn = $offer->starts_on->toDateString();
        $this->endsOn = $offer->ends_on->toDateString();
        $this->combinable = $offer->combinable;
    }

    /** Start and end on next month by default — the usual monthly program. */
    protected function defaultOfferDates(): void
    {
        $this->startsOn ??= now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->endsOn ??= now()->addMonthNoOverflow()->endOfMonth()->toDateString();
    }

    public function setType(string $type): void
    {
        abort_unless(array_key_exists($type, Offer::TYPE_LABELS), 422);
        $this->type = $type;

        $defaults = [Offer::TYPE_APR => 'Special CPO financing', Offer::TYPE_CASH => 'CPO cash discount', Offer::TYPE_WARRANTY => 'Extended warranty offer'];
        if (in_array($this->title, $defaults, true) || $this->title === '') {
            $this->title = $defaults[$type];
        }
    }

    /** @return array<string, mixed> validated attributes for the offers table */
    protected function validatedOfferAttributes(): array
    {
        $this->validate([
            'type'       => ['required', Rule::in(array_keys(Offer::TYPE_LABELS))],
            'title'      => ['required', 'string', 'max:120'],
            'models'     => ['nullable', 'string', 'max:500'],
            'yearMin'    => ['nullable', 'integer', 'between:1990,2100'],
            'yearMax'    => ['nullable', 'integer', 'between:1990,2100', 'gte:yearMin'],
            'provinces'  => ['array'],
            'provinces.*'=> [Rule::in(Offer::PROVINCES)],
            'apr'        => [$this->type === Offer::TYPE_APR ? 'required' : 'nullable', 'numeric', 'between:0,30'],
            'termMonths' => [$this->type === Offer::TYPE_APR ? 'required' : 'nullable', 'integer', 'between:6,120'],
            'lender'     => ['nullable', 'string', 'max:120'],
            'cashAmount' => [$this->type === Offer::TYPE_CASH ? 'required' : 'nullable', 'numeric', 'between:1,50000'],
            'benefit'    => [$this->type === Offer::TYPE_WARRANTY ? 'required' : 'nullable', 'string', 'max:200'],
            'conditions' => ['required', 'string', 'min:10', 'max:2000'],
            'startsOn'   => ['required', 'date'],
            'endsOn'     => ['required', 'date', 'after_or_equal:startsOn'],
        ], [
            'conditions.required' => 'Every offer needs its conditions and restrictions — they’re shown with it.',
        ], [
            'yearMin' => 'oldest model year', 'yearMax' => 'newest model year', 'apr' => 'rate', 'termMonths' => 'term',
            'cashAmount' => 'amount', 'startsOn' => 'start date', 'endsOn' => 'end date',
        ]);

        $models = collect(explode(',', $this->models))->map(fn ($model) => trim($model))->filter()->unique()->values()->all();

        return [
            'type'                 => $this->type,
            'title'                => trim($this->title),
            'models'               => $models ?: null,
            'year_min'             => $this->yearMin ? (int) $this->yearMin : null,
            'year_max'             => $this->yearMax ? (int) $this->yearMax : null,
            'provinces'            => array_values(array_intersect(Offer::PROVINCES, $this->provinces)) ?: null,
            'certified_only'       => $this->certifiedOnly,
            'apr'                  => $this->type === Offer::TYPE_APR ? round((float) $this->apr, 2) : null,
            'term_months'          => $this->type === Offer::TYPE_APR ? (int) $this->termMonths : null,
            'lender'               => $this->type === Offer::TYPE_APR ? (trim($this->lender) ?: null) : null,
            'cash_amount_in_cents' => $this->type === Offer::TYPE_CASH ? (int) round((float) $this->cashAmount * 100) : null,
            'benefit'              => $this->type === Offer::TYPE_WARRANTY ? trim($this->benefit) : null,
            'conditions'           => trim($this->conditions),
            'starts_on'            => $this->startsOn,
            'ends_on'              => $this->endsOn,
            'combinable'           => $this->combinable,
        ];
    }

    /** An unsaved Offer built from the form, for the live preview. */
    public function previewOffer(): Offer
    {
        $offer = new Offer([
            'type'                 => $this->type,
            'title'                => $this->title ?: Offer::TYPE_LABELS[$this->type],
            'make'                 => $this->make,
            'apr'                  => is_numeric($this->apr) ? $this->apr : 0,
            'term_months'          => is_numeric($this->termMonths) ? (int) $this->termMonths : null,
            'lender'               => $this->lender ?: null,
            'cash_amount_in_cents' => is_numeric($this->cashAmount) ? (int) round((float) $this->cashAmount * 100) : 0,
            'benefit'              => $this->benefit ?: 'Your benefit here',
            'conditions'           => $this->conditions ?: 'Conditions and restrictions appear here.',
        ]);
        $offer->ends_on = $this->endsOn ? Carbon::parse($this->endsOn) : now()->endOfMonth();

        return $offer;
    }
}
