<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealDocument extends Model
{
    protected $fillable = [
        'deal_id', 'slug', 'name', 'status', 'is_done', 'sort_order',
        'file_path', 'uploaded_at',
    ];

    protected $attributes = [
        'is_done'    => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_done'     => 'boolean',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * The canonical four documents every deal needs to finalize financing,
     * in display order. Mirrors the journey mockup's dealDocuments array.
     *
     * The driver's licence is the only one that can arrive pre-cleared: it's
     * marked done the moment identity is verified (Persona / Paays stamps
     * Deal::identity_verified_at at reserve). The other three are always
     * pending on creation — the buyer uploads them from My Garage.
     *
     * @return array<int, array{slug:string, name:string, status:string, is_done:bool}>
     */
    public static function blueprintFor(Deal $deal): array
    {
        $licenceVerified = $deal->identity_verified_at !== null;

        return [
            [
                'slug'    => 'driver_licence',
                'name'    => "Driver's licence",
                'status'  => $licenceVerified ? 'Verified' : 'Pending verification',
                'is_done' => $licenceVerified,
            ],
            [
                'slug'    => 'proof_of_insurance',
                'name'    => 'Proof of insurance',
                'status'  => 'Needed before you take delivery',
                'is_done' => false,
            ],
            [
                'slug'    => 'proof_of_income',
                'name'    => 'Proof of income',
                'status'  => 'Needed to finalize approval',
                'is_done' => false,
            ],
            [
                'slug'    => 'void_cheque',
                'name'    => 'Void cheque',
                'status'  => 'For pre-authorized payments',
                'is_done' => false,
            ],
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
