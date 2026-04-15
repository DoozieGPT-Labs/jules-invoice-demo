<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'invoice_number',
        'customer_name',
        'customer_email',
        'amount',
        'status',
        'due_date',
        'paid_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    /**
     * Get the user that owns the invoice.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include pending invoices.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include paid invoices.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope a query to only include overdue invoices.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', '!=', 'paid')
            ->where('due_date', '<', now());
    }

    /**
     * Determine if the invoice is overdue.
     */
    public function isOverdue(): bool
    {
        if ($this->status === 'paid') {
            return false;
        }

        return $this->due_date->isPast();
    }
}
