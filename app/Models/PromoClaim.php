<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;

// class PromoClaim extends Model
// {
//     protected $fillable = [
//         'email', 'promo_code', 'discount_value', 'is_used', 'used_at'
//     ];

//     protected $casts = [
//         'is_used' => 'boolean',
//         'used_at' => 'datetime',
//     ];
// }

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoClaim extends Model
{
    protected $fillable = [
        'email', 'promo_code', 'discount_value', 'is_used', 'used_at', 'expires_at' // Tambahkan expires_at
    ];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime', // Cast ke datetime (Carbon)
    ];
}
