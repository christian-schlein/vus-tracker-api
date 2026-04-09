<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Watchlist extends Model
{
    public $timestamps = false;

    protected $table = 'watchlist';

    protected $fillable = [
        'email',
        'email_verified_at',
        'verify_token',
        'variant_id',
        'gene_symbol',
        'variation_id',
        'hgvs',
        'alert_on_reclassification',
        'alert_on_new_submission',
        'alert_on_pubmed',
        'unsubscribe_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'alert_on_reclassification' => 'boolean',
        'alert_on_new_submission' => 'boolean',
        'alert_on_pubmed' => 'boolean',
    ];

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class, 'watchlist_id');
    }
}