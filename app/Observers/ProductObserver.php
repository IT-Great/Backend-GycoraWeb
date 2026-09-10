<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Wishlist;
use App\Mail\BackInStockMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProductObserver
{
    /**
     * Handle the Product "updated" event.
     * Akan dipanggil OTOMATIS oleh Laravel setiap kali ada update pada tabel products
     */
    public function updated(Product $product)
    {
        // 1. Cek secara spesifik apakah kolom 'stock' yang mengalami perubahan
        if ($product->wasChanged('stock')) {
            $oldStock = $product->getOriginal('stock');
            $newStock = $product->stock;

            // 2. LOGIKA TRIGGER: Jika stok awalnya HABIS (<= 0) dan sekarang TERSEDIA (> 0)
            if ($oldStock <= 0 && $newStock > 0) {
                $this->sendBackInStockAlerts($product);
            }
        }
    }

    private function sendBackInStockAlerts(Product $product)
    {
        // 3. Tarik semua pengguna yang menaruh produk ini di Wishlist mereka
        // Lakukan chunking jika user sangat banyak agar RAM server tidak meledak
        Wishlist::with('user')
            ->where('product_id', $product->id)
            ->chunk(100, function ($wishlists) use ($product) {
                foreach ($wishlists as $wishlist) {
                    $user = $wishlist->user;

                    // Pastikan user ada dan email valid
                    if ($user && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        try {
                            // 4. Masukkan pengiriman email ke dalam Queue (Antrean Background)
                            Mail::to($user->email)->queue(new BackInStockMail($product, $user));
                        } catch (\Exception $e) {
                            Log::error("Gagal blast Back-in-Stock email ke {$user->email}: " . $e->getMessage());
                        }
                    }
                }
            });

        Log::info("Back-In-Stock Alerts dipicu untuk produk: {$product->name}");
    }
}
