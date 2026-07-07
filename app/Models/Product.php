<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\Relations\BelongsTo;

// class Product extends Model
// {
//     protected $fillable = [
//         'category_id',
//         'sku',
//         'name',
//         'slug',
//         'description',
//         'benefits',
//         'price',
//         'stock',
//         'image_url',
//         'variant_images',
//         'variant_video',
//         'color',
//         'status'
//     ];

//     protected $casts = [
//         'price' => 'decimal:2',
//         'stock' => 'integer',
//         'variant_images' => 'array', // <-- CASTING KE ARRAY/JSON
//         'color' => 'array',          // <-- CASTING KE ARRAY/JSON
//     ];

//     /**
//      * Relasi ke Category
//      */
//     public function category(): BelongsTo
//     {
//         return $this->belongsTo(Category::class);
//     }

//     public function stocks()
//     {
//         // Pastikan Anda sudah membuat model ProductStock.php
//         return $this->hasMany(ProductStock::class);
//     }

//     // ====================================================================
//     // UNIVERSAL AUTO-HEALING URLs (ANTI FATAL ERROR)
//     // ====================================================================
//     public function getImageUrlAttribute($value)
//     {
//         // 1. Jika kosong sama sekali, kembalikan null
//         if (empty($value) || $value === 'null') return null;

//         // 2. Tangani kasus terburuk: Duplikasi URL (http://ip/https://domain...)
//         if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
//             return $matches[2];
//         }

//         // 3. Jika sudah berupa URL penuh yang valid
//         if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
//             return $value;
//         }

//         // 4. Jika hanya nama file atau path relatif biasa
//         // Asumsi file disimpan di storage/app/public/uploads
//         $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
//         return asset('storage' . $pathOnly);
//     }
// }

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'slug',
        'description',
        'wholesale_price',
        'benefits',
        'price',
        'prices',
        'discount_price', // Tambahkan field ini
        'discount_prices',
        'voucher_discount_price',
        'stock',
        'image_url',
        'variant_images',
        'variant_video',
        'color',
        'status'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2', // Casting juga sebagai decimal
        'voucher_discount_price' => 'decimal:2',
        'prices' => 'array',
        'discount_prices' => 'array', 
        'stock' => 'integer',
        'variant_images' => 'array',
        'color' => 'array',
    ];

    /**
     * Relasi ke Category
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks()
    {
        // Pastikan Anda sudah membuat model ProductStock.php
        return $this->hasMany(ProductStock::class);
    }

    // ====================================================================
    // UNIVERSAL AUTO-HEALING URLs (ANTI FATAL ERROR)
    // ====================================================================
    public function getImageUrlAttribute($value)
    {
        // 1. Jika kosong sama sekali, kembalikan null
        if (empty($value) || $value === 'null') return null;

        // 2. Tangani kasus terburuk: Duplikasi URL (http://ip/https://domain...)
        if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
            return $matches[2];
        }

        // 3. Jika sudah berupa URL penuh yang valid
        if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
            return $value;
        }

        // 4. Jika hanya nama file atau path relatif biasa
        // Asumsi file disimpan di storage/app/public/uploads
        $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
        return asset('storage' . $pathOnly);
    }
}
