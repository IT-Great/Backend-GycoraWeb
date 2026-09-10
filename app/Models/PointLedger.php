<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointLedger extends Model
{
    // Nonaktifkan updated_at karena ini adalah ledger statis
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'transaction_id', 'reference_type', 'description',
        'debit', 'credit', 'balance', 'previous_hash', 'signature', 'created_at'
    ];

    protected static function boot()
    {
        parent::boot();

        // 🛡️ IMMUTABILITY SHIELD: Blokir segala upaya modifikasi atau penghapusan data
        static::updating(function ($ledger) {
            throw new \Exception("SECURITY VIOLATION: Point Ledger records are immutable and cannot be updated.");
        });

        static::deleting(function ($ledger) {
            throw new \Exception("SECURITY VIOLATION: Point Ledger records are immutable and cannot be deleted.");
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
