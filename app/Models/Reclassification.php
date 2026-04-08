<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reclassification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'variant_id', 'gene_id', 'variation_id_clinvar', 'hgvs',
        'old_classification', 'new_classification', 'reclassified_at',
        'submitter', 'condition',
    ];

    protected function casts(): array
    {
        return [
            'reclassified_at' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function gene(): BelongsTo
    {
        return $this->belongsTo(Gene::class);
    }
}
