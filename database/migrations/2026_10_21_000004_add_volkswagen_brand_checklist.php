<?php

use App\Support\BrandChecklists;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Volkswagen Canada's 112-point Certified Pre-Owned checklist, from vw.ca. */
    public function up(): void
    {
        BrandChecklists::install([
            'make'        => 'Volkswagen',
            'name'        => 'Volkswagen Certified Pre-Owned',
            'badge_label' => 'VW Certified',
            'summary'     => 'Inspected to Volkswagen Canada’s 112-point Certified Pre-Owned checklist.',
            'file'        => 'vw-cpo.txt',
        ]);
    }

    public function down(): void
    {
        BrandChecklists::remove(['Volkswagen']);
    }
};
