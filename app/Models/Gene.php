<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Gene extends Model
{
    protected $fillable = [
        'symbol', 'full_name', 'omim_id', 'medgen_id', 'orphanet_id', 'hgnc_id',
        'total_variants', 'pathogenic_count', 'likely_pathogenic_count',
        'vus_count', 'likely_benign_count', 'benign_count',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function reclassifications(): HasMany
    {
        return $this->hasMany(Reclassification::class);
    }

    public function conditions(): BelongsToMany
    {
        return $this->belongsToMany(Condition::class);
    }

    public function recomputeCounts(): void
    {
        $counts = $this->variants()
            ->selectRaw("
                COUNT(*) as total,
                SUM(classification = 'pathogenic') as p,
                SUM(classification = 'likely_pathogenic') as lp,
                SUM(classification = 'uncertain_significance') as vus,
                SUM(classification = 'likely_benign') as lb,
                SUM(classification = 'benign') as b
            ")
            ->first();

        $this->update([
            'total_variants' => $counts->total,
            'pathogenic_count' => $counts->p,
            'likely_pathogenic_count' => $counts->lp,
            'vus_count' => $counts->vus,
            'likely_benign_count' => $counts->lb,
            'benign_count' => $counts->b,
        ]);
    }
}
