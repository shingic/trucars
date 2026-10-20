<?php

use App\Support\BrandChecklists;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** INFINITI Canada's 169-point Certified Pre-Owned checklist (same form as Nissan Canada's). */
    public function up(): void
    {
        BrandChecklists::install([
            'make'        => 'Infiniti',
            'name'        => 'Certified INFINITI',
            'badge_label' => 'Certified INFINITI',
            'summary'     => 'Inspected to INFINITI Canada’s Certified Pre-Owned checklist.',
            'file'        => 'infiniti-cpo.txt',
        ]);
    }

    public function down(): void
    {
        BrandChecklists::remove(['Infiniti']);
    }
};
