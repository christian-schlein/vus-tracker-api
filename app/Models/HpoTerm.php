<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class HpoTerm extends Model
{
    public $timestamps = false;

    protected $fillable = ['hpo_id', 'name'];

    public function genes(): BelongsToMany
    {
        return $this->belongsToMany(Gene::class, 'hpo_gene', 'hpo_term_id', 'gene_id')
            ->withPivot('gene_symbol', 'disease_id');
    }

    /**
     * Fuzzy search: FULLTEXT first, then LIKE fallback, plus SOUNDEX.
     */
    public function scopeFuzzySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            // FULLTEXT boolean mode
            $q->whereRaw(
                'MATCH(name) AGAINST(? IN BOOLEAN MODE)',
                [$term . '*']
            )
            // LIKE fallback
            ->orWhere('name', 'LIKE', '%' . $term . '%')
            // SOUNDEX phonetic matching
            ->orWhereRaw('SOUNDEX(name) = SOUNDEX(?)', [$term]);
        });
    }
}
