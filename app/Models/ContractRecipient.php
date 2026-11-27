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
        'returned_storage_path',
        'returned_filename',
        'returned_mime_type',
        'returned_file_size',
        'review_feedback',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'opened_at' => 'datetime',
        'returned_file_size' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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
