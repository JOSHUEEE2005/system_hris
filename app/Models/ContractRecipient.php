<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractRecipient extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'received_at',
        'opened_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'opened_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
