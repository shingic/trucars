<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A franchise brand. Every dealer belongs to one, and the brand's settings —
 * its CPO inspection checklist and its offers & incentives — apply to all of
 * its dealers the same way. Assign the brand; everything else follows.
 */
class Brand extends Model
{
    protected $fillable = ['name', 'cpo_program_name', 'cpo_max_model_years', 'cpo_max_km', 'cpo_benefits', 'cpo_fine_print', 'cpo_source_url', 'cpo_checked_on', 'cpo_benefits_live'];

    protected function casts(): array
    {
        return [
            'cpo_benefits'      => 'array',
            'cpo_checked_on'    => 'date',
            'cpo_benefits_live' => 'boolean',
        ];
    }

    /**
     * The certified program benefits as clean rows (blank titles dropped).
     *
     * @return array<int, array{icon: string, title: string, detail: string}>
     */
    public function certifiedBenefitRows(): array
    {
        return collect($this->cpo_benefits ?? [])
            ->map(fn ($row) => [
                'icon'   => array_key_exists($row['icon'] ?? '', \App\Support\CertifiedBenefits::ICONS) ? $row['icon'] : 'check',
                'title'  => trim((string) ($row['title'] ?? '')),
                'detail' => trim((string) ($row['detail'] ?? '')),
            ])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }

    public function dealers(): HasMany
    {
        return $this->hasMany(Dealer::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** The brand's CPO inspection checklists (brand checklists are keyed by make). */
    public function checklists(): Builder
    {
        return InspectionProgram::query()->whereNull('dealer_id')->whereRaw('lower(make) = ?', [mb_strtolower($this->name)]);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $this->name), 0, 2));
    }
}
