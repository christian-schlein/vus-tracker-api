<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variant extends Model
{
    protected $fillable = [
        'gene_id', 'variation_id', 'hgvs', 'classification', 'review_status',
        'submitter', 'date_last_evaluated', 'chromosome', 'position',
        'ref_allele', 'alt_allele', 'rcv_accession', 'condition',
        'phenotype_ids', 'origin', 'assembly', 'raw_json', 'clinvar_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'phenotype_ids' => 'array',
            'raw_json' => 'array',
            'date_last_evaluated' => 'date',
            'clinvar_updated_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function gene(): BelongsTo
    {
        return $this->belongsTo(Gene::class);
    }
}
