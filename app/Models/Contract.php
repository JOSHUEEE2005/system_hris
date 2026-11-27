<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $fillable = [
        'title',
        'original_filename',
        'storage_path',
        'original_pdf_path',
        'mime_type',
        'file_size',
        'recipient_type',
        'department_id',
        'folder_id',
        'created_by',
        'sent_at',
        'content_html',
        'response_mode',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ContractFolder::class, 'folder_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ContractRecipient::class);
    }
}
