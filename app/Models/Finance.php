<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Finance extends Model
{
    protected $fillable = [
        'journal_id',
        'name',
        'description',
        'type',
        'amount',
        'date',
        'payment_method',
        'payment_reference',
        'payment_note',
        'attachment',
        'created_by',
        'updated_by',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
