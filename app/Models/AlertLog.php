<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    public $timestamps = false;

    protected $table = 'alert_log';

    protected $fillable = [
        'watchlist_id',
        'alert_type',
        'subject',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class, 'watchlist_id');
    }
}