<?php

use App\Support\BrandChecklists;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Brand checklists for Subaru Canada (152 points, from its CPO inspection
     * form) and Audi Canada (336 points, from the Audi Certified :plus
     * inspection user guide). Shown on cars the feed marks certified. A brand
     * that already has a checklist is left alone.
     */
    private const BRANDS = [
        [
            'make'        => 'Subaru',
            'name'        => 'Subaru Certified Pre-Owned',
            'badge_label' => 'Subaru CPO',
            'summary'     => 'Inspected to Subaru Canada’s Certified Pre-Owned checklist.',
            'file'        => 'subaru-cpo.txt',
        ],
        [
            'make'        => 'Audi',
            'name'        => 'Audi Certified :plus',
            'badge_label' => 'Audi Certified :plus',
            'summary'     => 'Inspected to Audi Canada’s Certified :plus checklist.',
            'file'        => 'audi-certified-plus.txt',
        ],
    ];

    public function up(): void
    {
        foreach (self::BRANDS as $brand) {
            BrandChecklists::install($brand);
        }
    }

    public function down(): void
    {
        BrandChecklists::remove(array_column(self::BRANDS, 'make'));
    }
};
