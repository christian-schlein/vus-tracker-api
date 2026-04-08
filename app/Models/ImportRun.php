<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source', 'status', 'variants_processed', 'reclass_processed',
        'errors', 'error_log', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
