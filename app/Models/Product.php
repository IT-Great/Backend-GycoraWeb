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
use Laravel\Scout\Searchable; // 👈 1. Import Searchable Trait

class Product extends Model
{
    use Searchable; // 👈 2. Gunakan Trait ini
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
        'wholesale_price', 'wholesale_prices',
        'voucher_discount_price', 'voucher_discount_prices',
        'is_bundle_active', // <-- BARU
        'bundle_price',     // <-- BARU
        'bundle_prices',    // <-- BARU
        'bundle_start_date', // <-- BARU
        'bundle_end_date',  // <-- BARU
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
        'wholesale_prices' => 'array',
        'bundle_price' => 'decimal:2', // <-- BARU
        'voucher_discount_prices' => 'array',
        'bundle_prices' => 'array', // <-- BARU
        'is_bundle_active' => 'boolean', // <-- BARU
        'bundle_start_date' => 'datetime', // <-- BARU
        'bundle_end_date' => 'datetime', // <-- BARU
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

    /**
     * 3. Tentukan data apa saja yang akan dikirim (di-index) ke Meilisearch.
     * Kita hanya mengirim teks yang relevan untuk memperingan beban memory.
     */
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'category_name' => $this->category ? $this->category->name : '',
            // Jangan masukkan 'description' jika terlalu panjang dan tidak relevan untuk pencarian cepat
        ];
    }
}
