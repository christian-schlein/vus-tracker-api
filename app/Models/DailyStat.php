<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStat extends Model
{
    public $timestamps = false;
    protected $table = 'daily_stats';

    protected $fillable = [
        'date', 'total_variants', 'total_genes', 'total_vus',
        'total_reclassifications', 'new_variants_today', 'new_reclass_today',
        'snapshot_json',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'snapshot_json' => 'array',
        ];
    }
}
