<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One point on a dealer's inspection checklist. */
class InspectionPoint extends Model
{
    protected $fillable = ['inspection_program_id', 'position', 'section', 'label'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(InspectionProgram::class, 'inspection_program_id');
    }
}
