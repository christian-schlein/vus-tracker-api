<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Condition extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'medgen_id', 'omim_id', 'orphanet_id'];

    public function genes(): BelongsToMany
    {
        return $this->belongsToMany(Gene::class);
    }
}
