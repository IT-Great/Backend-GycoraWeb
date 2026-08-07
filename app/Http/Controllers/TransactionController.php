<?php

// namespace App\Http\Controllers;

// use App\Mail\LowStockAlertMail;
// use App\Mail\RefundResultMail;
// use App\Models\Cart;
// use App\Models\Payment;
// use App\Models\Product;
// use App\Models\ProductStock;
// use App\Models\PromoClaim;
// use App\Models\PromoCode;
// use App\Models\Transaction;
// use App\Models\TransactionDetail;
// use App\Models\User;
// use Carbon\Carbon;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Str;
// use Xendit\Configuration;
// use Xendit\Invoice\CreateInvoiceRequest;
// use Xendit\Invoice\InvoiceApi;
// use Xendit\Refund\CreateRefund;
// use Xendit\Refund\RefundApi;
// use Xendit\XenditSdkException;
// use App\Services\BiteshipService;

// class TransactionController extends Controller
// {
//     public function __construct()
//     {
//         Configuration::setXenditKey(config('services.xendit.secret_key'));
//     }

//     // =================================================================================
//     // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
//     // =================================================================================
//     public function restoreProductStock($productId, $quantityToRestore)
//     {
//         if ($quantityToRestore <= 0) {
//             return;
//         }

//         // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
//         $product = Product::lockForUpdate()->find($productId);
//         if (! $product) {
//             return;
//         }

//         $remainingToRestore = $quantityToRestore;

//         // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
//         // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
//         $incompleteBatches = ProductStock::where('product_id', $productId)
//             ->whereColumn('quantity', '<', 'initial_quantity')
//             ->orderBy('created_at', 'asc')
//             ->lockForUpdate() // Kunci baris batch ini selama transaksi berlangsung
//             ->get();

//         foreach ($incompleteBatches as $batch) {
//             if ($remainingToRestore <= 0) {
//                 break;
//             }

//             $spaceAvailable = $batch->initial_quantity - $batch->quantity;

//             if ($spaceAvailable >= $remainingToRestore) {
//                 // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
//                 $batch->increment('quantity', $remainingToRestore);
//                 $remainingToRestore = 0;
//             } else {
//                 // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
//                 $batch->increment('quantity', $spaceAvailable);
//                 $remainingToRestore -= $spaceAvailable;
//             }
//         }

//         // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
//         if ($remainingToRestore > 0) {
//             $latestBatch = ProductStock::where('product_id', $productId)
//                 ->orderBy('created_at', 'desc')
//                 ->lockForUpdate()
//                 ->first();

//             if ($latestBatch) {
//                 // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
//                 $latestBatch->increment('quantity', $remainingToRestore);
//                 $latestBatch->increment('initial_quantity', $remainingToRestore);
//             } else {
//                 // Jika benar-benar tidak ada batch sama sekali, buat batch pengembalian khusus
//                 ProductStock::create([
//                     'product_id' => $productId,
//                     'batch_code' => 'RET-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
//                     'quantity' => $remainingToRestore,
//                     'initial_quantity' => $remainingToRestore,
//                 ]);
//             }
//         }

//         // 4. Kembalikan total stok di tabel master
//         $product->increment('stock', $quantityToRestore);
//     }

//     // --- USER ACTIONS ---
//     // public function checkout(Request $request)
//     // {
//     //     // ... (Validasi request tetap sama) ...
//     //     $request->validate([
//     //         'address_id' => 'required',
//     //         'shipping_method' => 'required|in:free,biteship',
//     //         'use_points' => 'nullable|integer|min:0',
//     //         'cart_ids' => 'required|array',
//     //         'cart_ids.*' => 'exists:carts,id',
//     //         'shipping_cost' => 'nullable|numeric',
//     //         'courier_company' => 'nullable|string',
//     //         'courier_type' => 'nullable|string',
//     //         'delivery_type' => 'nullable|string',
//     //     ]);

//     //     $user = $request->user();
//     //     $cartItems = Cart::with('product')
//     //         ->where('user_id', $user->id)
//     //         ->whereIn('id', $request->cart_ids)
//     //         ->get();

//     //     if ($cartItems->isEmpty()) {
//     //         return response()->json(['message' => 'No items selected for checkout'], 400);
//     //     }

//     //     // // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
//     //     // $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
//     //     //     $totalAmount = 0;
//     //     //     foreach ($cartItems as $item) {
//     //     //         $currentPrice = $item->product->discount_price ?? $item->product->price;
//     //     //         $totalAmount += ($currentPrice * $item->quantity);
//     //     //     }

//     //     //     // =========================================================================
//     //     //     // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
//     //     //     // =========================================================================
//     //     //     $promoDiscountAmount = 0;
//     //     //     $appliedPromoCode = null;

//     //     //     if (! empty($request->promo_code)) {
//     //     //         // Kunci (Lock) baris promo claim agar tidak bisa di-klik ganda bersamaan
//     //     //         $promoClaim = PromoClaim::where('email', $user->email)
//     //     //             ->where('promo_code', strtoupper($request->promo_code))
//     //     //             ->lockForUpdate()
//     //     //             ->first();

//     //     //         if (! $promoClaim) {
//     //     //             throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
//     //     //         }
//     //     //         if ($promoClaim->is_used) {
//     //     //             throw new \Exception('Kode Promo sudah pernah digunakan.');
//     //     //         }
//     //     //         if ($totalAmount < 50000) { // Syarat batas minimal belanja
//     //     //             throw new \Exception('Minimum belanja untuk memakai promo ini adalah Rp 50.000');
//     //     //         }

//     //     //         $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
//     //     //         $appliedPromoCode = $promoClaim->promo_code;

//     //     //         // Tandai promo sudah digunakan
//     //     //         $promoClaim->update([
//     //     //             'is_used' => true,
//     //     //             'used_at' => now(),
//     //     //         ]);
//     //     //     }

//     //     //     // Total harga setelah promo dipotong
//     //     //     $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

//     //     //     // =========================================================================
//     //     //     // 2. POTONG POIN DARI SISA HARGA SETELAH PROMO (Mencegah Tagihan Minus)
//     //     //     // =========================================================================
//     //     //     $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

//     //     //     // $earnedPoints = 0;
//     //     //     // if ($user->is_membership) {
//     //     //     //     $earnedPoints = floor($totalAmount / 100000);
//     //     //     // }

//     //     //     $earnedPoints = $user->is_membership ? floor($totalAmount / 100000) : 0;

//     //     //     $pointsUsed = 0;
//     //     //     $pointDiscountAmount = 0;
//     //     //     if ($request->use_points > 0 && $user->is_membership) {
//     //     //         $pointsUsed = min($request->use_points, $user->point);
//     //     //         // $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
//     //     //         // if ($pointsUsed > 0) {
//     //     //         //     $user->decrement('point', $pointsUsed);
//     //     //         // }

//     //     //         // Poin maksimal yang bisa dipakai = Sisa harga setelah promo
//     //     //         $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
//     //     //         $pointDiscountAmount = $maxUsableDiscount;

//     //     //         // Konversi kembali ke poin riil yang terpakai
//     //     //         $actualPointsDeducted = floor($maxUsableDiscount / 1000);
//     //     //         $pointsUsed = $actualPointsDeducted;

//     //     //         if ($pointsUsed > 0) {
//     //     //             $user->decrement('point', $pointsUsed);
//     //     //         }
//     //     //     }

//     //     //     $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //     //     $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //     //     $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //     //     $transaction = Transaction::create([
//     //     //         'user_id' => $user->id,
//     //     //         'address_id' => $request->address_id,
//     //     //         'shipping_method' => $request->shipping_method,
//     //     //         'shipping_cost' => $totalShippingCost,
//     //     //         'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//     //     //         'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//     //     //         'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//     //     //         'order_id' => $orderId,
//     //     //         'total_amount' => $totalAmount,
//     //     //         'status' => 'pending',
//     //     //         'point' => $earnedPoints,
//     //     //         'points_used' => $pointsUsed,
//     //     //         'promo_code' => $appliedPromoCode,
//     //     //         'promo_discount' => $promoDiscountAmount,
//     //     //     ]);

//     //     // // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
//     //     // $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

//     //     //     // =========================================================================
//     //     //     // [PERBAIKAN KRITIS]: Kunci data User spesifik ini selama proses checkout.
//     //     //     // Jika ada 2 request masuk bersamaan, request kedua akan disuruh antre
//     //     //     // menunggu request pertama selesai memotong poin.
//     //     //     // =========================================================================
//     //     //     $lockedUser = \App\Models\User::lockForUpdate()->find($user->id);

//     //     //     $totalAmount = 0;
//     //     //     foreach ($cartItems as $item) {
//     //     //         $currentPrice = $item->product->discount_price ?? $item->product->price;
//     //     //         $totalAmount += ($currentPrice * $item->quantity);
//     //     //     }

//     //     //     // // =========================================================================
//     //     //     // // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
//     //     //     // // =========================================================================
//     //     //     // $promoDiscountAmount = 0;
//     //     //     // $appliedPromoCode = null;

//     //     //     // if (! empty($request->promo_code)) {
//     //     //     //     // Pastikan menggunakan $lockedUser->email untuk validasi
//     //     //     //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
//     //     //     //         ->where('promo_code', strtoupper($request->promo_code))
//     //     //     //         ->lockForUpdate()
//     //     //     //         ->first();

//     //     //     //     if (! $promoClaim) {
//     //     //     //         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
//     //     //     //     }
//     //     //     //     if ($promoClaim->is_used) {
//     //     //     //         throw new \Exception('Kode Promo sudah pernah digunakan.');
//     //     //     //     }
//     //     //     //     if ($totalAmount < 50000) {
//     //     //     //         throw new \Exception('Minimum belanja untuk memakai promo ini adalah Rp 50.000');
//     //     //     //     }

//     //     //     //     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
//     //     //     //     $appliedPromoCode = $promoClaim->promo_code;

//     //     //     //     $promoClaim->update([
//     //     //     //         'is_used' => true,
//     //     //     //         'used_at' => now(),
//     //     //     //     ]);
//     //     //     // }

//     //     //     // $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

//     //     //     // =========================================================================
//     //     //     // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
//     //     //     // Mendukung Promo Claim (Email) dan Promo Codes (Voucher Bos)
//     //     //     // =========================================================================
//     //     //     $promoDiscountAmount = 0;
//     //     //     $appliedPromoCode = null;

//     //     //     if (! empty($request->promo_code)) {
//     //     //         $inputCode = strtoupper($request->promo_code);

//     //     //         // Cek 1: Promo Claim (Milik Spesifik User)
//     //     //         $promoClaim = PromoClaim::where('email', $lockedUser->email)
//     //     //             ->where('promo_code', $inputCode)
//     //     //             ->lockForUpdate()
//     //     //             ->first();

//     //     //         if ($promoClaim) {
//     //     //             if ($promoClaim->is_used) {
//     //     //                 throw new \Exception('Subscriber Promo ini sudah pernah digunakan.');
//     //     //             }
//     //     //             if ($totalAmount < 50000) {
//     //     //                 throw new \Exception('Minimum belanja Rp 50.000 untuk promo ini.');
//     //     //             }

//     //     //             $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
//     //     //             $appliedPromoCode = $promoClaim->promo_code;

//     //     //             $promoClaim->update([
//     //     //                 'is_used' => true,
//     //     //                 'used_at' => now(),
//     //     //             ]);
//     //     //         } else {
//     //     //             // Cek 2: Promo Code Global (Voucher Bebas)
//     //     //             $voucher = \App\Models\PromoCode::where('code', $inputCode)
//     //     //                 ->lockForUpdate()
//     //     //                 ->first();

//     //     //             if (!$voucher) {
//     //     //                 throw new \Exception('Kode Promo/Voucher tidak valid.');
//     //     //             }
//     //     //             if ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) {
//     //     //                 throw new \Exception('Voucher ini sudah kedaluwarsa.');
//     //     //             }
//     //     //             if ($voucher->times_used >= $voucher->max_uses) {
//     //     //                 throw new \Exception('Kuota penggunaan voucher ini sudah habis.');
//     //     //             }
//     //     //             if ($totalAmount < 50000) { // Asumsi voucher juga butuh min 50rb
//     //     //                 throw new \Exception('Minimum belanja Rp 50.000 untuk memakai voucher ini.');
//     //     //             }

//     //     //             $promoDiscountAmount = min($voucher->discount_value, $totalAmount);
//     //     //             $appliedPromoCode = $voucher->code;

//     //     //             // Tambah angka "times_used" agar voucher hangus/berkurang kuotanya
//     //     //             $voucher->increment('times_used');
//     //     //         }
//     //     //     }

//     //     //     $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

//     //     //     // =========================================================================
//     //     //     // 2. POTONG POIN DARI SISA HARGA SETELAH PROMO (Mencegah Tagihan Minus)
//     //     //     // =========================================================================
//     //     //     $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

//     //     //     // Gunakan $lockedUser, bukan $user dari luar transaksi
//     //     //     $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;

//     //     //     $pointsUsed = 0;
//     //     //     $pointDiscountAmount = 0;

//     //     //     if ($request->use_points > 0 && $lockedUser->is_membership) {
//     //     //         // Karena kita menggunakan $lockedUser->point, angkanya dijamin 100% akurat dan terhindar dari double-spending
//     //     //         $pointsUsed = min($request->use_points, $lockedUser->point);

//     //     //         $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
//     //     //         $pointDiscountAmount = $maxUsableDiscount;

//     //     //         $actualPointsDeducted = floor($maxUsableDiscount / 1000);
//     //     //         $pointsUsed = $actualPointsDeducted;

//     //     //         if ($pointsUsed > 0) {
//     //     //             // Potong poin dari instance yang sudah dilock
//     //     //             $lockedUser->decrement('point', $pointsUsed);
//     //     //         }
//     //     //     }

//     //     //     $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //     //     $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //     //     $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //     //     // Saat membuat transaksi, gunakan $lockedUser->id
//     //     //     $transaction = Transaction::create([
//     //     //         'user_id' => $lockedUser->id,
//     //     //         'address_id' => $request->address_id,
//     //     //         // ... (Sisa variabel di array create() ini tetap sama seperti kode Anda) ...
//     //     //         'shipping_method' => $request->shipping_method,
//     //     //         'shipping_cost' => $totalShippingCost,
//     //     //         'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//     //     //         'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//     //     //         'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//     //     //         'order_id' => $orderId,
//     //     //         'total_amount' => $totalAmount,
//     //     //         'status' => 'pending',
//     //     //         'point' => $earnedPoints,
//     //     //         'points_used' => $pointsUsed,
//     //     //         'promo_code' => $appliedPromoCode,
//     //     //         'promo_discount' => $promoDiscountAmount,
//     //     //     ]);

//     //     //     $xenditItems = [];
//     //     //     foreach ($cartItems as $item) {
//     //     //         $product = Product::lockForUpdate()->find($item->product_id);
//     //     //         if ($product->stock < $item->quantity) {
//     //     //             throw new \Exception("Stock {$product->name} insufficient");
//     //     //         }

//     //     //         $price = $item->product->discount_price ?? $item->product->price;

//     //     //         TransactionDetail::create([
//     //     //             'transaction_id' => $transaction->id,
//     //     //             'product_id' => $item->product_id,
//     //     //             'quantity' => $item->quantity,
//     //     //             'price' => $price,
//     //     //             'color' => $item->color, // <--- BARU: Simpan riwayat warna ke tabel transaksi
//     //     //         ]);

//     //     //         // ... (Logika Potong FIFO Batch Anda tetap sama di sini) ...
//     //     //         $remainingQuantityToDeduct = $item->quantity;
//     //     //         $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
//     //     //         $legacyStock = $product->stock - $totalBatchQuantity;

//     //     //         if ($legacyStock > 0) {
//     //     //             $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
//     //     //             ProductStock::create([
//     //     //                 'product_id' => $product->id,
//     //     //                 'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
//     //     //                 'quantity' => 0,
//     //     //                 'initial_quantity' => $takeFromLegacy,
//     //     //             ]);
//     //     //             $remainingQuantityToDeduct -= $takeFromLegacy;
//     //     //         }

//     //     //         if ($remainingQuantityToDeduct > 0) {
//     //     //             $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
//     //     //             foreach ($activeBatches as $batch) {
//     //     //                 if ($remainingQuantityToDeduct <= 0) {
//     //     //                     break;
//     //     //                 }
//     //     //                 if ($batch->quantity >= $remainingQuantityToDeduct) {
//     //     //                     $batch->decrement('quantity', $remainingQuantityToDeduct);
//     //     //                     $remainingQuantityToDeduct = 0;
//     //     //                 } else {
//     //     //                     $remainingQuantityToDeduct -= $batch->quantity;
//     //     //                     $batch->update(['quantity' => 0]);
//     //     //                 }
//     //     //             }
//     //     //         }

//     //     //         if ($remainingQuantityToDeduct > 0) {
//     //     //             throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
//     //     //         }
//     //     //         $product->decrement('stock', $item->quantity);

//     //     //         // [PERBAIKAN XENDIT] Tambahkan informasi warna di struk pembayaran Xendit
//     //     //         $productName = $product->name;
//     //     //         if (!empty($item->color)) {
//     //     //             $productName .= ' - ' . $item->color;
//     //     //         }

//     //     //         $xenditItems[] = [
//     //     //             'name' => $productName,
//     //     //             'quantity' => $item->quantity,
//     //     //             'price' => (int) $price,
//     //     //             'category' => 'PHYSICAL_PRODUCT',
//     //     //         ];
//     //     //     }

//     //     //     // Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//     //     //     return [
//     //     //         'transaction' => $transaction,
//     //     //         'xenditItems' => $xenditItems,
//     //     //         'totalAmount' => $totalAmount,
//     //     //         'totalShippingCost' => $totalShippingCost,
//     //     //         'pointDiscountAmount' => $pointDiscountAmount,
//     //     //         'pointsUsed' => $pointsUsed,
//     //     //         'totalQuantity' => $totalQuantity,
//     //     //         'promoCode' => $appliedPromoCode,
//     //     //         'promoDiscountAmount' => $promoDiscountAmount,
//     //     //     ];
//     //     // }); // <-- DB TRANSACTION SELESAI & LOCK DILEPAS DI SINI!

//     //     $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

//     //         $lockedUser = \App\Models\User::lockForUpdate()->find($user->id);

//     //         // // =========================================================================
//     //         // // 1. TENTUKAN TIPE PROMO
//     //         // // =========================================================================
//     //         // $promoType = $request->promo_type ?? null;
//     //         // $inputCode = !empty($request->promo_code) ? strtoupper($request->promo_code) : null;

//     //         // $promoDiscountAmount = 0;
//     //         // $appliedPromoCode = null;

//     //         // if ($inputCode && $promoType === 'claim') {
//     //         //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
//     //         //         ->where('promo_code', $inputCode)
//     //         //         ->lockForUpdate()
//     //         //         ->first();

//     //         //     if (!$promoClaim || $promoClaim->is_used) {
//     //         //         throw new \Exception('Subscriber Promo tidak valid atau sudah digunakan.');
//     //         //     }

//     //         //     $appliedPromoCode = $promoClaim->promo_code;
//     //         //     $promoClaim->update(['is_used' => true, 'used_at' => now()]);
//     //         // }
//     //         // else if ($inputCode && $promoType === 'voucher') {
//     //         //     $voucher = \App\Models\PromoCode::where('code', $inputCode)
//     //         //         ->lockForUpdate()
//     //         //         ->first();

//     //         //     if (!$voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses) {
//     //         //         throw new \Exception('Voucher tidak valid atau sudah habis kuotanya.');
//     //         //     }

//     //         //     $appliedPromoCode = $voucher->code;
//     //         //     $voucher->increment('times_used');
//     //         // }

//     //         // // =========================================================================
//     //         // // 2. HITUNG TOTAL HARGA (Pre-calculation)
//     //         // // =========================================================================
//     //         // $totalAmount = 0;

//     //         // foreach ($cartItems as $item) {
//     //         //     // Pastikan produk ada dan stok cukup sebelum melangkah lebih jauh
//     //         //     $product = Product::find($item->product_id);
//     //         //     if (!$product || $product->stock < $item->quantity) {
//     //         //         throw new \Exception("Stok tidak mencukupi untuk item di keranjang Anda.");
//     //         //     }

//     //         //     $priceToUse = $product->discount_price ?? $product->price;

//     //         //     if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//     //         //         $priceToUse = $product->voucher_discount_price;
//     //         //     }

//     //         //     $totalAmount += ($priceToUse * $item->quantity);
//     //         // }

//     //         // // =========================================================================
//     //         // // 3. POTONG DISKON GLOBAL (HANYA UNTUK PROMO 'CLAIM')
//     //         // // =========================================================================
//     //         // if ($promoType === 'claim') {
//     //         //     if ($totalAmount < 50000) {
//     //         //          throw new \Exception('Minimum belanja Rp 50.000 untuk menggunakan promo ini.');
//     //         //     }
//     //         //     $promoDiscountAmount = min(250000, $totalAmount);
//     //         // }

//     //         // =========================================================================
//     //         // 1. TENTUKAN TIPE PROMO
//     //         // =========================================================================
//     //         $promoType = $request->promo_type ?? null;
//     //         $inputCode = !empty($request->promo_code) ? strtoupper($request->promo_code) : null;

//     //         $appliedPromoCode = null;
//     //         $isClaimPromo = false; // [BARU] Flag untuk hitungan matematika

//     //         if ($inputCode && $promoType === 'claim') {
//     //             $promoClaim = PromoClaim::where('email', $lockedUser->email)
//     //                 ->where('promo_code', $inputCode)
//     //                 ->lockForUpdate()
//     //                 ->first();

//     //             if (!$promoClaim || $promoClaim->is_used) {
//     //                 throw new \Exception('Subscriber Promo tidak valid atau sudah digunakan.');
//     //             }

//     //             $appliedPromoCode = $promoClaim->promo_code;
//     //             $promoClaim->update(['is_used' => true, 'used_at' => now()]);
//     //             $isClaimPromo = true; // Tandai bahwa user pakai promo 10% + 10k
//     //         }
//     //         else if ($inputCode && $promoType === 'voucher') {
//     //             $voucher = \App\Models\PromoCode::where('code', $inputCode)
//     //                 ->lockForUpdate()
//     //                 ->first();

//     //             if (!$voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses) {
//     //                 throw new \Exception('Voucher tidak valid atau sudah habis kuotanya.');
//     //             }

//     //             $appliedPromoCode = $voucher->code;
//     //             $voucher->increment('times_used');
//     //         }

//     //         // =========================================================================
//     //         // 2. HITUNG TOTAL HARGA BARANG (Pre-calculation)
//     //         // =========================================================================
//     //         $totalAmount = 0;

//     //         foreach ($cartItems as $item) {
//     //             $product = Product::find($item->product_id);
//     //             if (!$product || $product->stock < $item->quantity) {
//     //                 throw new \Exception("Stok tidak mencukupi untuk item di keranjang Anda.");
//     //             }

//     //             $priceToUse = $product->discount_price ?? $product->price;

//     //             if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//     //                 $priceToUse = $product->voucher_discount_price;
//     //             }

//     //             $totalAmount += ($priceToUse * $item->quantity);
//     //         }

//     //         // Hitung Ongkir Dasar
//     //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //         $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //         // =========================================================================
//     //         // 3. POTONG DISKON (10% + 10K)
//     //         // =========================================================================
//     //         $promoDiscountAmount = 0;

//     //         if ($isClaimPromo) {
//     //             if ($totalAmount < 50000) {
//     //                  throw new \Exception('Minimum belanja Rp 50.000 untuk menggunakan promo ini.');
//     //             }

//     //             // Diskon 10% Barang
//     //             $productDiscount = floor($totalAmount * 0.10);

//     //             // Subsidi Ongkir Maks 10.000
//     //             $shippingSubsidy = min(10000, $totalShippingCost);

//     //             // Total Potongan Diskon
//     //             $promoDiscountAmount = $productDiscount + $shippingSubsidy;
//     //         }

//     //         $totalAfterPromo = max(0, ($totalAmount + $totalShippingCost) - $promoDiscountAmount);

//     //         // $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

//     //         // =========================================================================
//     //         // 4. POTONG POIN LOYALTY
//     //         // =========================================================================
//     //         $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
//     //         $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
//     //         $pointsUsed = 0;
//     //         $pointDiscountAmount = 0;

//     //         if ($request->use_points > 0 && $lockedUser->is_membership) {
//     //             $pointsUsed = min($request->use_points, $lockedUser->point);
//     //             $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
//     //             $pointDiscountAmount = $maxUsableDiscount;
//     //             $pointsUsed = floor($maxUsableDiscount / 1000);

//     //             if ($pointsUsed > 0) {
//     //                 $lockedUser->decrement('point', $pointsUsed);
//     //             }
//     //         }

//     //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //         $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //         // =========================================================================
//     //         // 5. SIMPAN TRANSAKSI UTAMA TERLEBIH DAHULU (PENTING!)
//     //         // =========================================================================
//     //         $transaction = Transaction::create([
//     //             'user_id' => $lockedUser->id,
//     //             'address_id' => $request->address_id,
//     //             'shipping_method' => $request->shipping_method,
//     //             'shipping_cost' => $totalShippingCost,
//     //             'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//     //             'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//     //             'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//     //             'order_id' => $orderId,
//     //             'total_amount' => $totalAmount,
//     //             'status' => 'pending',
//     //             'point' => $earnedPoints,
//     //             'points_used' => $pointsUsed,
//     //             'promo_code' => $appliedPromoCode,
//     //             'promo_discount' => $promoDiscountAmount,
//     //         ]);

//     //         // =========================================================================
//     //         // 6. LOOPING UNTUK POTONG STOK & SIMPAN DETAIL TRANSAKSI
//     //         // =========================================================================
//     //         $xenditItems = [];

//     //         foreach ($cartItems as $item) {
//     //             // Kunci produk untuk update stok dengan aman
//     //             $product = Product::lockForUpdate()->find($item->product_id);

//     //             $priceToUse = $product->discount_price ?? $product->price;
//     //             if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//     //                 $priceToUse = $product->voucher_discount_price;
//     //             }

//     //             // Simpan Detail dengan ID Transaksi yang sudah dibuat
//     //             TransactionDetail::create([
//     //                 'transaction_id' => $transaction->id, // <--- KINI ID TERISI!
//     //                 'product_id' => $item->product_id,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => $priceToUse,
//     //                 'color' => $item->color,
//     //             ]);

//     //             // Logika Pemotongan Stok
//     //             $remainingQuantityToDeduct = $item->quantity;
//     //             $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
//     //             $legacyStock = $product->stock - $totalBatchQuantity;

//     //             if ($legacyStock > 0) {
//     //                 $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
//     //                 ProductStock::create([
//     //                     'product_id' => $product->id,
//     //                     'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
//     //                     'quantity' => 0,
//     //                     'initial_quantity' => $takeFromLegacy,
//     //                 ]);
//     //                 $remainingQuantityToDeduct -= $takeFromLegacy;
//     //             }

//     //             if ($remainingQuantityToDeduct > 0) {
//     //                 $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
//     //                 foreach ($activeBatches as $batch) {
//     //                     if ($remainingQuantityToDeduct <= 0) break;
//     //                     if ($batch->quantity >= $remainingQuantityToDeduct) {
//     //                         $batch->decrement('quantity', $remainingQuantityToDeduct);
//     //                         $remainingQuantityToDeduct = 0;
//     //                     } else {
//     //                         $remainingQuantityToDeduct -= $batch->quantity;
//     //                         $batch->update(['quantity' => 0]);
//     //                     }
//     //                 }
//     //             }

//     //             // Potong stok utama
//     //             $product->decrement('stock', $item->quantity);

//     //             // =========================================================================
//     //             // [BARU] LOW STOCK ALERT TRIGGER
//     //             // =========================================================================
//     //             if ($product->stock <= 5) {
//     //                 try {
//     //                     \Illuminate\Support\Facades\Mail::to('gycora.essence@gmail.com')
//     //                         ->send(new \App\Mail\LowStockAlertMail($product));

//     //                     \Illuminate\Support\Facades\Log::warning("Low Stock Alert sent for Product: {$product->name} (Sisa: {$product->stock})");
//     //                 } catch (\Exception $e) {
//     //                     \Illuminate\Support\Facades\Log::error('Gagal kirim email low stock: ' . $e->getMessage());
//     //                 }
//     //             }

//     //             // Format Xendit Items
//     //             $productName = $product->name;
//     //             if (!empty($item->color)) { $productName .= ' - ' . $item->color; }

//     //             $xenditItems[] = [
//     //                 'name' => $productName,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => (int) $priceToUse,
//     //                 'category' => 'PHYSICAL_PRODUCT',
//     //             ];
//     //         }

//     //         return [
//     //             'transaction' => $transaction,
//     //             'xenditItems' => $xenditItems,
//     //             'totalAmount' => $totalAmount,
//     //             'totalShippingCost' => $totalShippingCost,
//     //             'pointDiscountAmount' => $pointDiscountAmount,
//     //             'pointsUsed' => $pointsUsed,
//     //             'totalQuantity' => $totalQuantity,
//     //             'promoCode' => $appliedPromoCode,
//     //             'promoDiscountAmount' => $promoDiscountAmount,
//     //             'promoType' => $promoType,
//     //         ];
//     //     });

//     //     // 2. PANGGIL API PIHAK KETIGA DENGAN AMAN DI LUAR TRANSAKSI
//     //     try {
//     //         // $externalId = 'PAY-'.$transactionData['transaction']->order_id;

//     //         // if ($transactionData['pointDiscountAmount'] > 0) {
//     //         //     $transactionData['xenditItems'][] = [
//     //         //         'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
//     //         //         'quantity' => 1,
//     //         //         'price' => -(int) $transactionData['pointDiscountAmount'],
//     //         //         'category' => 'DISCOUNT',
//     //         //     ];
//     //         // }

//     //         // if ($transactionData['totalShippingCost'] > 0) {
//     //         //     $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
//     //         //     $transactionData['xenditItems'][] = [
//     //         //         'name' => 'Shipping Cost ('.$request->courier_company.')',
//     //         //         'quantity' => (int) $transactionData['totalQuantity'],
//     //         //         'price' => (int) $baseShippingRate,
//     //         //         'category' => 'SHIPPING_FEE',
//     //         //     ];
//     //         // }

//     //         // $finalAmount = (int) $transactionData['totalAmount'] + $transactionData['totalShippingCost'] - $transactionData['pointDiscountAmount'];

//     //         // $invoiceRequest = new CreateInvoiceRequest([
//     //         //     'external_id' => $externalId,
//     //         //     'payer_email' => $user->email,
//     //         //     'amount' => $finalAmount,
//     //         //     'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
//     //         //     'items' => $transactionData['xenditItems'],
//     //         //     'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
//     //         //     'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//     //         // ]);

//     //         $externalId = 'PAY-'.$transactionData['transaction']->order_id;

//     //         // [PERBAIKAN 1]: Masukkan Item Diskon Promo ke Xendit
//     //         if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
//     //             $transactionData['xenditItems'][] = [
//     //                 'name' => 'Promo Code: '.$transactionData['promoCode'],
//     //                 'quantity' => 1,
//     //                 'price' => -(int) $transactionData['promoDiscountAmount'], // Harus Minus
//     //                 'category' => 'DISCOUNT',
//     //             ];
//     //         }

//     //         if ($transactionData['pointDiscountAmount'] > 0) {
//     //             $transactionData['xenditItems'][] = [
//     //                 'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
//     //                 'quantity' => 1,
//     //                 'price' => -(int) $transactionData['pointDiscountAmount'],
//     //                 'category' => 'DISCOUNT',
//     //             ];
//     //         }

//     //         if ($transactionData['totalShippingCost'] > 0) {
//     //             $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
//     //             $transactionData['xenditItems'][] = [
//     //                 'name' => 'Shipping Cost ('.$request->courier_company.')',
//     //                 'quantity' => (int) $transactionData['totalQuantity'],
//     //                 'price' => (int) $baseShippingRate,
//     //                 'category' => 'SHIPPING_FEE',
//     //             ];
//     //         }

//     //         // [PERBAIKAN 2]: Kurangi Promo Discount dari Final Amount Xendit
//     //         $finalAmount = (int) $transactionData['totalAmount']
//     //                      + $transactionData['totalShippingCost']
//     //                      - $transactionData['pointDiscountAmount']
//     //                      - ($transactionData['promoDiscountAmount'] ?? 0); // Kurangi Promo di sini!

//     //         // ================================================================
//     //         // [BARU] LOG AUDIT HARGA SEBELUM TERKIRIM KE XENDIT
//     //         // Cek file log di: storage/logs/laravel.log
//     //         // ================================================================
//     //         Log::info('XENDIT INVOICE CALCULATION', [
//     //             'order_id' => $transactionData['transaction']->order_id,
//     //             'subtotal_barang' => $transactionData['totalAmount'],
//     //             'ongkos_kirim' => $transactionData['totalShippingCost'],
//     //             'diskon_poin' => $transactionData['pointDiscountAmount'],
//     //             'diskon_promo' => $transactionData['promoDiscountAmount'] ?? 0,
//     //             'GRAND_TOTAL_FINAL' => $finalAmount,
//     //             'xendit_items_count' => count($transactionData['xenditItems']),
//     //         ]);

//     //         $invoiceRequest = new CreateInvoiceRequest([
//     //             'external_id' => $externalId,
//     //             'payer_email' => $user->email,
//     //             'amount' => $finalAmount,
//     //             'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
//     //             'items' => $transactionData['xenditItems'],
//     //             'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
//     //             'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//     //         ]);

//     //         $api = new InvoiceApi;
//     //         $invoice = $api->createInvoice($invoiceRequest);

//     //         Payment::create([
//     //             'transaction_id' => $transactionData['transaction']->id,
//     //             'external_id' => $externalId,
//     //             'checkout_url' => $invoice['invoice_url'],
//     //             'amount' => $transactionData['transaction']->total_amount,
//     //             'status' => 'pending',
//     //         ]);

//     //         // =========================================================================
//     //         // [PERBAIKAN KRITIS]: HAPUS KERANJANG DI SINI!
//     //         // Eksekusi hanya jika Xendit BERHASIL membuat invoice tanpa melempar Exception.
//     //         // =========================================================================
//     //         Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//     //         // Cache::tags(['catalog'])->flush();
//     //         Cache::flush();

//     //         return response()->json(['checkout_url' => $invoice['invoice_url']], 201);

//     //     } catch (\Exception $e) {
//     //         // Jika Xendit Gagal, Batalkan Transaksi dan kembalikan stok secara manual
//     //         Log::error('Xendit Invoice Creation Failed: '.$e->getMessage());
//     //         app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

//     //         return response()->json(['message' => 'Payment gateway error. Please try again.'], 500);
//     //     }
//     // }

//     // --- USER ACTIONS ---
//     public function checkout(Request $request)
//     {
//         $request->validate([
//             'address_id' => 'required',
//             'shipping_method' => 'required|in:free,biteship',
//             'use_points' => 'nullable|integer|min:0',
//             'cart_ids' => 'required|array',
//             'cart_ids.*' => 'exists:carts,id',
//             'shipping_cost' => 'nullable|numeric',
//             'courier_company' => 'nullable|string',
//             'courier_type' => 'nullable|string',
//             'delivery_type' => 'nullable|string',
//         ]);

//         $user = $request->user();
//         $cartItems = Cart::with('product')
//             ->where('user_id', $user->id)
//             ->whereIn('id', $request->cart_ids)
//             ->get();

//         if ($cartItems->isEmpty()) {
//             return response()->json(['message' => 'No items selected for checkout'], 400);
//         }

//         // 1. LAKUKAN PROSES DATABASE (DB TRANSACTION)
//         $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

//             $lockedUser = User::lockForUpdate()->find($user->id);

//             // =========================================================================
//             // 1. TENTUKAN TIPE PROMO
//             // =========================================================================
//             $promoType = $request->promo_type ?? null;
//             $inputCode = ! empty($request->promo_code) ? strtoupper($request->promo_code) : null;

//             $appliedPromoCode = null;
//             $isClaimPromo = false;

//             // if ($inputCode && $promoType === 'claim') {
//             //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
//             //         ->where('promo_code', $inputCode)
//             //         ->lockForUpdate()
//             //         ->first();

//             //     if (!$promoClaim || $promoClaim->is_used) {
//             //         throw new \Exception('Subscriber Promo tidak valid atau sudah digunakan.');
//             //     }

//             //     $appliedPromoCode = $promoClaim->promo_code;
//             //     $promoClaim->update(['is_used' => true, 'used_at' => now()]);
//             //     $isClaimPromo = true;
//             // }

//             // Di dalam TransactionController.php (Fungsi Checkout)
//             if ($inputCode && $promoType === 'claim') {
//                 $promoClaim = PromoClaim::where('email', $lockedUser->email)
//                     ->where('promo_code', $inputCode)
//                     ->lockForUpdate()
//                     ->first();

//                 // Validasi Ganda di saat Checkout (Mencegah kecurangan)
//                 if (! $promoClaim || $promoClaim->is_used) {
//                     throw new \Exception('Promo tidak valid atau sudah digunakan.');
//                 }
//                 if ($promoClaim->expires_at && Carbon::now()->greaterThan($promoClaim->expires_at)) {
//                     throw new \Exception('Promo sudah kedaluwarsa.');
//                 }

//                 $appliedPromoCode = $promoClaim->promo_code;

//                 // UBAH STATUS MENJADI TERPAKAI (LOGIKA C)
//                 $promoClaim->update([
//                     'is_used' => true,
//                     'used_at' => now(),
//                 ]);

//                 $isClaimPromo = true;
//             } elseif ($inputCode && $promoType === 'voucher') {
//                 $voucher = PromoCode::where('code', $inputCode)
//                     ->lockForUpdate()
//                     ->first();

//                 if (! $voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses) {
//                     throw new \Exception('Voucher tidak valid atau sudah habis kuotanya.');
//                 }

//                 $appliedPromoCode = $voucher->code;
//                 $voucher->increment('times_used');
//             }

//             // =========================================================================
//             // 2. HITUNG TOTAL HARGA (Pre-calculation)
//             // =========================================================================
//             $totalAmount = 0;

//             foreach ($cartItems as $item) {
//                 $product = Product::find($item->product_id);
//                 if (! $product || $product->stock < $item->quantity) {
//                     throw new \Exception('Stok tidak mencukupi untuk item di keranjang Anda.');
//                 }

//                 // $priceToUse = $product->discount_price ?? $product->price;

//                 // if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//                 //     $priceToUse = $product->voucher_discount_price;
//                 // }

//                 // --- LOGIKA HARGA BARU ---
//                 $priceToUse = $product->price;

//                 // 1. Cek jika dia reseller dan produk punya harga grosir
//                 if ($lockedUser->usertype === 'reseller' && $product->wholesale_price > 0) {
//                     $priceToUse = $product->wholesale_price;
//                 }
//                 // 2. Jika bukan reseller, cek diskon publik
//                 elseif ($product->discount_price > 0 && $product->discount_price < $product->price) {
//                     $priceToUse = $product->discount_price;
//                 }

//                 // 3. Timpa jika menggunakan voucher khusus
//                 if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//                     $priceToUse = $product->voucher_discount_price;
//                 }
//                 // -------------------------

//                 $totalAmount += ($priceToUse * $item->quantity);
//             }

//             // Hitung Ongkir Dasar
//             $totalQuantity = $cartItems->sum('quantity') ?: 1;
//             $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//             $totalShippingCost = $baseShippingRate * $totalQuantity;

//             // =========================================================================
//             // 3. POTONG DISKON (10% + 10K)
//             // =========================================================================
//             $promoDiscountAmount = 0;

//             if ($isClaimPromo) {
//                 if ($totalAmount < 50000) {
//                     throw new \Exception('Minimum belanja Rp 50.000 untuk menggunakan promo ini.');
//                 }

//                 // Diskon 10% Barang
//                 $productDiscount = floor($totalAmount * 0.10);

//                 // Subsidi Ongkir Maks 10.000
//                 $shippingSubsidy = min(10000, $totalShippingCost);

//                 // Total Potongan Diskon
//                 $promoDiscountAmount = $productDiscount + $shippingSubsidy;
//             }

//             $totalAfterPromo = max(0, ($totalAmount + $totalShippingCost) - $promoDiscountAmount);

//             // =========================================================================
//             // 4. POTONG POIN LOYALTY
//             // =========================================================================
//             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
//             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
//             $pointsUsed = 0;
//             $pointDiscountAmount = 0;

//             if ($request->use_points > 0 && $lockedUser->is_membership) {
//                 $pointsUsed = min($request->use_points, $lockedUser->point);
//                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
//                 $pointDiscountAmount = $maxUsableDiscount;
//                 $pointsUsed = floor($maxUsableDiscount / 1000);

//                 if ($pointsUsed > 0) {
//                     $lockedUser->decrement('point', $pointsUsed);
//                 }
//             }

//             // =========================================================================
//             // 5. SIMPAN TRANSAKSI UTAMA TERLEBIH DAHULU (PENTING!)
//             // =========================================================================
//             $transaction = Transaction::create([
//                 'user_id' => $lockedUser->id,
//                 'address_id' => $request->address_id,
//                 'shipping_method' => $request->shipping_method,
//                 'shipping_cost' => $totalShippingCost,
//                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//                 'order_id' => $orderId,
//                 'total_amount' => $totalAmount,
//                 'status' => 'pending',
//                 'point' => $earnedPoints,
//                 'points_used' => $pointsUsed,
//                 'promo_code' => $appliedPromoCode,
//                 'promo_discount' => $promoDiscountAmount,
//             ]);

//             // =========================================================================
//             // 6. LOOPING UNTUK POTONG STOK & SIMPAN DETAIL TRANSAKSI
//             // =========================================================================
//             $xenditItems = [];

//             foreach ($cartItems as $item) {
//                 // Kunci produk untuk update stok dengan aman
//                 $product = Product::lockForUpdate()->find($item->product_id);

//                 // $priceToUse = $product->discount_price ?? $product->price;
//                 // if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//                 //     $priceToUse = $product->voucher_discount_price;
//                 // }

//                 // --- LOGIKA HARGA BARU ---
//                 $priceToUse = $product->price;

//                 if ($lockedUser->usertype === 'reseller' && $product->wholesale_price > 0) {
//                     $priceToUse = $product->wholesale_price;
//                 } elseif ($product->discount_price > 0 && $product->discount_price < $product->price) {
//                     $priceToUse = $product->discount_price;
//                 }

//                 if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
//                     $priceToUse = $product->voucher_discount_price;
//                 }
//                 // -------------------------

//                 // Simpan Detail dengan ID Transaksi yang sudah dibuat
//                 TransactionDetail::create([
//                     'transaction_id' => $transaction->id,
//                     'product_id' => $item->product_id,
//                     'quantity' => $item->quantity,
//                     'price' => $priceToUse,
//                     'color' => $item->color,
//                 ]);

//                 // Logika Pemotongan Stok
//                 $remainingQuantityToDeduct = $item->quantity;
//                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
//                 $legacyStock = $product->stock - $totalBatchQuantity;

//                 if ($legacyStock > 0) {
//                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
//                     ProductStock::create([
//                         'product_id' => $product->id,
//                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
//                         'quantity' => 0,
//                         'initial_quantity' => $takeFromLegacy,
//                     ]);
//                     $remainingQuantityToDeduct -= $takeFromLegacy;
//                 }

//                 if ($remainingQuantityToDeduct > 0) {
//                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
//                     foreach ($activeBatches as $batch) {
//                         if ($remainingQuantityToDeduct <= 0) {
//                             break;
//                         }
//                         if ($batch->quantity >= $remainingQuantityToDeduct) {
//                             $batch->decrement('quantity', $remainingQuantityToDeduct);
//                             $remainingQuantityToDeduct = 0;
//                         } else {
//                             $remainingQuantityToDeduct -= $batch->quantity;
//                             $batch->update(['quantity' => 0]);
//                         }
//                     }
//                 }

//                 // Potong stok utama
//                 $product->decrement('stock', $item->quantity);

//                 // =========================================================================
//                 // [BARU] LOW STOCK ALERT TRIGGER
//                 // =========================================================================
//                 if ($product->stock <= 5) {
//                     try {
//                         Mail::to('gycora.essence@gmail.com')
//                             ->send(new LowStockAlertMail($product));

//                         Log::warning("Low Stock Alert sent for Product: {$product->name} (Sisa: {$product->stock})");
//                     } catch (\Exception $e) {
//                         Log::error('Gagal kirim email low stock: '.$e->getMessage());
//                     }
//                 }

//                 // Format Xendit Items
//                 $productName = $product->name;
//                 if (! empty($item->color)) {
//                     $productName .= ' - '.$item->color;
//                 }

//                 $xenditItems[] = [
//                     'name' => $productName,
//                     'quantity' => $item->quantity,
//                     'price' => (int) $priceToUse,
//                     'category' => 'PHYSICAL_PRODUCT',
//                 ];
//             }

//             return [
//                 'transaction' => $transaction,
//                 'xenditItems' => $xenditItems,
//                 'totalAmount' => $totalAmount,
//                 'totalShippingCost' => $totalShippingCost,
//                 'pointDiscountAmount' => $pointDiscountAmount,
//                 'pointsUsed' => $pointsUsed,
//                 'totalQuantity' => $totalQuantity,
//                 'promoCode' => $appliedPromoCode,
//                 'promoDiscountAmount' => $promoDiscountAmount,
//                 'promoType' => $promoType,
//             ];
//         });

//         // 2. PANGGIL API PIHAK KETIGA DENGAN AMAN DI LUAR TRANSAKSI
//         try {
//             $externalId = 'PAY-'.$transactionData['transaction']->order_id;

//             // [PERBAIKAN 1]: Masukkan Item Diskon Promo ke Xendit
//             if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
//                 $transactionData['xenditItems'][] = [
//                     'name' => 'Promo Code: '.($transactionData['promoCode'] ?? 'CLAIM10'),
//                     'quantity' => 1,
//                     'price' => -(int) $transactionData['promoDiscountAmount'], // Harus Minus
//                     'category' => 'DISCOUNT',
//                 ];
//             }

//             if ($transactionData['pointDiscountAmount'] > 0) {
//                 $transactionData['xenditItems'][] = [
//                     'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
//                     'quantity' => 1,
//                     'price' => -(int) $transactionData['pointDiscountAmount'],
//                     'category' => 'DISCOUNT',
//                 ];
//             }

//             if ($transactionData['totalShippingCost'] > 0) {
//                 $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
//                 $transactionData['xenditItems'][] = [
//                     'name' => 'Shipping Cost ('.$request->courier_company.')',
//                     'quantity' => (int) $transactionData['totalQuantity'],
//                     'price' => (int) $baseShippingRate,
//                     'category' => 'SHIPPING_FEE',
//                 ];
//             }

//             // [PERBAIKAN 2]: Kurangi Promo Discount dari Final Amount Xendit
//             $finalAmount = (int) $transactionData['totalAmount']
//                          + $transactionData['totalShippingCost']
//                          - $transactionData['pointDiscountAmount']
//                          - ($transactionData['promoDiscountAmount'] ?? 0); // Kurangi Promo di sini!

//             // ================================================================
//             // [BARU] LOG AUDIT HARGA SEBELUM TERKIRIM KE XENDIT
//             // ================================================================
//             Log::info('XENDIT INVOICE CALCULATION', [
//                 'order_id' => $transactionData['transaction']->order_id,
//                 'subtotal_barang' => $transactionData['totalAmount'],
//                 'ongkos_kirim' => $transactionData['totalShippingCost'],
//                 'diskon_poin' => $transactionData['pointDiscountAmount'],
//                 'diskon_promo' => $transactionData['promoDiscountAmount'] ?? 0,
//                 'GRAND_TOTAL_FINAL' => $finalAmount,
//                 'xendit_items_count' => count($transactionData['xenditItems']),
//             ]);

//             $invoiceRequest = new CreateInvoiceRequest([
//                 'external_id' => $externalId,
//                 'payer_email' => $user->email,
//                 'amount' => $finalAmount,
//                 'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
//                 'items' => $transactionData['xenditItems'],
//                 'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
//                 'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//             ]);

//             $api = new InvoiceApi;
//             $invoice = $api->createInvoice($invoiceRequest);

//             Payment::create([
//                 'transaction_id' => $transactionData['transaction']->id,
//                 'external_id' => $externalId,
//                 'checkout_url' => $invoice['invoice_url'],
//                 'amount' => $transactionData['transaction']->total_amount,
//                 'status' => 'pending',
//             ]);

//             // =========================================================================
//             // [PERBAIKAN KRITIS]: HAPUS KERANJANG DI SINI!
//             // Eksekusi hanya jika Xendit BERHASIL membuat invoice tanpa melempar Exception.
//             // =========================================================================
//             Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//             Cache::flush();

//             return response()->json(['checkout_url' => $invoice['invoice_url']], 201);

//         } catch (\Exception $e) {
//             // Jika Xendit Gagal, Batalkan Transaksi dan kembalikan stok secara manual
//             Log::error('Xendit Invoice Creation Failed: '.$e->getMessage());
//             app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

//             return response()->json(['message' => 'Payment gateway error. Please try again.'], 500);
//         }
//     }

//     public function index(Request $request)
//     {
//         // Eager load 'payment' untuk mendapatkan checkout_url
//         $transactions = Transaction::with(['details.product', 'payment', 'address'])
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();

//         return response()->json($transactions);
//     }

//     // Melihat semua transaksi (Sisi Admin)
//     public function allTransactions()
//     {
//         // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
//         $transactions = Transaction::with(['user', 'details.product', 'address'])
//             ->latest()
//             ->get();

//         return response()->json($transactions);
//     }

//     public function cancelOrder(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if (! in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
//             return response()->json(['message' => 'Cannot cancel this order.'], 400);
//         }

//         // PRE-CHECK BITESHIP (Berjalan di luar transaksi database agar tidak memberatkan server)
//         if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && ! empty($transaction->biteship_order_id)) {
//             try {
//                 $res = Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key'),
//                 ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $data = $res->json();
//                     $biteshipStatus = strtolower($data['status'] ?? '');

//                     $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
//                     if (in_array($biteshipStatus, $unCancellableStatuses)) {
//                         return response()->json([
//                             'message' => 'Cannot cancel: The package is already being processed by the courier.',
//                         ], 400);
//                     }

//                     Http::withHeaders([
//                         'Authorization' => config('services.biteship.api_key'),
//                     ])->delete('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
//                 }
//             } catch (\Exception $e) {
//                 return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
//             }

//             // AUTO-REFUND XENDIT
//             try {
//                 $transaction->load('payment');
//                 if ($transaction->payment && $transaction->payment->external_id) {
//                     $invoiceApi = new InvoiceApi;
//                     $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//                     if (! empty($invoices) && count($invoices) > 0) {
//                         $xenditInvoiceId = $invoices[0]['id'];
//                         $refundApi = new RefundApi;

//                         $refundRequest = new CreateRefund([
//                             'invoice_id' => $xenditInvoiceId,
//                             'reason' => 'REQUESTED_BY_CUSTOMER',
//                             'amount' => (int) $transaction->total_amount,
//                             'metadata' => ['order_id' => $transaction->order_id],
//                         ]);

//                         $refundApi->createRefund(null, null, $refundRequest);
//                     }
//                 }
//             } catch (\Exception $e) {
//                 // JIKA REFUND GAGAL (TAPI KURIR SUDAH DIBATALKAN), LEMPAR KE REFUND MANUAL TAPI KEMBALIKAN STOKNYA
//                 DB::transaction(function () use ($transaction) {
//                     $transaction->update(['status' => 'refund_manual_required']);
//                     foreach ($transaction->details as $detail) {
//                         // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
//                         $this->restoreProductStock($detail->product_id, $detail->quantity);
//                     }
//                 });

//                 return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
//             }
//         }

//         // [PENTING] Bungkus pembatalan status dan pengembalian stok dalam DB Transaction
//         DB::transaction(function () use ($transaction) {
//             // Re-fetch dan Lock untuk mencegah error paralel
//             $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

//             if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
//                 $lockedTransaction->update([
//                     'status' => 'cancelled',
//                     'shipping_status' => 'cancelled', // [PERBAIKAN] Sinkronisasi status pengiriman
//                 ]);

//                 // [PERBAIKAN] KEMBALIKAN POIN YANG HANGUS
//                 if ($lockedTransaction->points_used > 0) {
//                     $lockedTransaction->user->increment('point', $lockedTransaction->points_used);
//                 }

//                 // [BARU] KEMBALIKAN PROMO CODE JIKA TRANSAKSI BATAL
//                 if ($lockedTransaction->promo_code) {
//                     PromoClaim::where('email', $lockedTransaction->user->email)
//                         ->where('promo_code', $lockedTransaction->promo_code)
//                         ->update(['is_used' => false, 'used_at' => null]);
//                 }

//                 if ($lockedTransaction->payment) {
//                     $lockedTransaction->payment->update(['status' => 'EXPIRED']);
//                 }

//                 // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
//                 foreach ($lockedTransaction->details as $detail) {
//                     $this->restoreProductStock($detail->product_id, $detail->quantity);
//                 }
//             }
//         });

//         // Cache::tags(['catalog'])->flush();
//         Cache::flush();

//         return response()->json(['message' => 'Order cancelled successfully']);
//     }

//     // public function confirmComplete(Request $request, $id)
//     // {
//     //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//     //     if ($transaction->status !== 'processing') {
//     //         return response()->json(['message' => 'Order cannot be completed yet.'], 400);
//     //     }

//     //     $transaction->update(['status' => 'completed']);

//     //     // [PERBAIKAN] Cek syarat membership setelah admin komplit manual
//     //     $this->checkAndAssignMembership($transaction->user);

//     //     return response()->json(['message' => 'Order completed!']);
//     // }

//     public function confirmComplete(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if ($transaction->status !== 'processing') {
//             return response()->json(['message' => 'Order cannot be completed yet.'], 400);
//         }

//         $transaction->update(['status' => 'completed']);
//         $this->checkAndAssignMembership($transaction->user);

//         // [PERBAIKAN MUTLAK] Jangan lupakan poin pelanggan yang menyelesaikan pesanan manual!
//         $transaction->user->refresh();
//         if ($transaction->point > 0 && $transaction->user->is_membership) {
//             $transaction->user->increment('point', $transaction->point);
//         }

//         return response()->json(['message' => 'Order completed!']);
//     }

namespace App\Http\Controllers;

use App\Mail\LowStockAlertMail;
use App\Mail\RefundResultMail;
use App\Models\Cart;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;
use Xendit\Refund\CreateRefund;
use Xendit\Refund\RefundApi;
use Xendit\XenditSdkException;
use App\Services\BiteshipService;
use App\Jobs\SendShippingUpdateJob;

class TransactionController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    // =================================================================================
    // HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
    // =================================================================================
    public function restoreProductStock($productId, $quantityToRestore)
    {
        if ($quantityToRestore <= 0) return;

        $product = Product::lockForUpdate()->find($productId);
        if (! $product) return;

        $remainingToRestore = $quantityToRestore;

        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($incompleteBatches as $batch) {
            if ($remainingToRestore <= 0) break;
            $spaceAvailable = $batch->initial_quantity - $batch->quantity;
            if ($spaceAvailable >= $remainingToRestore) {
                $batch->increment('quantity', $remainingToRestore);
                $remainingToRestore = 0;
            } else {
                $batch->increment('quantity', $spaceAvailable);
                $remainingToRestore -= $spaceAvailable;
            }
        }

        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id', $productId)->orderBy('created_at', 'desc')->lockForUpdate()->first();
            if ($latestBatch) {
                $latestBatch->increment('quantity', $remainingToRestore);
                $latestBatch->increment('initial_quantity', $remainingToRestore);
            } else {
                ProductStock::create([
                    'product_id' => $productId,
                    'batch_code' => 'RET-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore,
                ]);
            }
        }

        $product->increment('stock', $quantityToRestore);
    }

    // --- USER ACTIONS ---
    public function checkout(Request $request)
    {
        $request->validate([
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'use_points' => 'nullable|integer|min:0',
            'cart_ids' => 'required|array',
            'cart_ids.*' => 'exists:carts,id',
            'shipping_cost' => 'nullable|numeric',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'delivery_type' => 'nullable|string',
        ]);

        $user = $request->user();
        $cartItems = Cart::with('product')->where('user_id', $user->id)->whereIn('id', $request->cart_ids)->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'No items selected for checkout'], 400);
        }

        $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
            $lockedUser = User::lockForUpdate()->find($user->id);

            // =========================================================================
            // 1. TENTUKAN TIPE PROMO
            // =========================================================================
            $promoType = $request->promo_type ?? null;
            $inputCode = ! empty($request->promo_code) ? strtoupper($request->promo_code) : null;
            $appliedPromoCode = null;
            $isClaimPromo = false;

            if ($inputCode && $promoType === 'claim') {
                $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $inputCode)->lockForUpdate()->first();
                if (! $promoClaim || $promoClaim->is_used) {
                    throw new \Exception('Promo tidak valid atau sudah digunakan.');
                }
                if ($promoClaim->expires_at && Carbon::now()->greaterThan($promoClaim->expires_at)) {
                    throw new \Exception('Promo sudah kedaluwarsa.');
                }
                $appliedPromoCode = $promoClaim->promo_code;
                $promoClaim->update(['is_used' => true, 'used_at' => now()]);
                $isClaimPromo = true;
            } elseif ($inputCode && $promoType === 'voucher') {
                $voucher = PromoCode::where('code', $inputCode)->lockForUpdate()->first();
                if (! $voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses) {
                    throw new \Exception('Voucher tidak valid atau sudah habis kuotanya.');
                }
                $appliedPromoCode = $voucher->code;
                $voucher->increment('times_used');
            }

            // =========================================================================
            // 2. HITUNG TOTAL HARGA (LOGIKA DRIVER-PARTNER BUNDLE)
            // =========================================================================
            $totalAmount = 0;
            $itemTotals = []; // Simpan harga FIX per cart ID
            $driversPool = [];
            $partnersPool = [];

            $totalCartQty = $cartItems->sum('quantity');
            $isWholesaleGlobal = $lockedUser->usertype === 'reseller' && $totalCartQty >= 24;

            foreach ($cartItems as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);
                if (! $product || $product->stock < $item->quantity) {
                    throw new \Exception('Stok tidak mencukupi untuk item di keranjang Anda.');
                }

                $normalPrice = $product->price;

                if ($isWholesaleGlobal && $product->wholesale_price > 0) {
                    $normalPrice = $product->wholesale_price;
                } elseif ($product->discount_price > 0 && $product->discount_price < $product->price) {
                    $normalPrice = $product->discount_price;
                }

                if ($promoType === 'voucher' && $product->voucher_discount_price > 0) {
                    $normalPrice = $product->voucher_discount_price;
                }

                $itemTotals[$item->id] = 0;

                // Jika Grosir aktif, bypass semua urusan Bundle
                if ($isWholesaleGlobal && $product->wholesale_price > 0) {
                    $itemTotals[$item->id] = $normalPrice * $item->quantity;
                    continue;
                }

                $sku = strtoupper($product->sku ?? '');
                $isEGB = str_starts_with($sku, 'EGB');

                $isBundleActiveFlag = filter_var($product->is_bundle_active, FILTER_VALIDATE_BOOLEAN);
                $dateStr = $product->bundle_end_date;
                $isValidDate = true;
                if (!empty($dateStr) && $dateStr !== '0000-00-00 00:00:00') {
                    try { $isValidDate = Carbon::parse($dateStr)->isFuture(); } catch (\Exception $e) { $isValidDate = false; }
                }

                $isDriver = $isEGB && $isBundleActiveFlag && $isValidDate && $product->bundle_price > 0;

                for ($i = 0; $i < $item->quantity; $i++) {
                    $poolItem = [
                        'cart_id' => $item->id,
                        'normal_price' => $normalPrice,
                        'bundle_price' => $product->bundle_price ?? 0
                    ];

                    if ($isDriver) {
                        $driversPool[] = $poolItem;
                    } elseif (!$isEGB) {
                        $partnersPool[] = $poolItem;
                    } else {
                        $itemTotals[$item->id] += $normalPrice;
                    }
                }
            }

            // --- EKSEKUSI PENJODOHAN BUNDLE DI BACKEND ---
            if (count($driversPool) > 0 && count($partnersPool) > 0) {
                usort($driversPool, function($a, $b) {
                    return $b['bundle_price'] <=> $a['bundle_price'];
                });

                while (count($driversPool) > 0 && count($partnersPool) > 0) {
                    $driver = array_shift($driversPool);
                    $partner = array_shift($partnersPool);

                    $pairNormalPrice = $driver['normal_price'] + $partner['normal_price'];
                    $pairBundlePrice = $driver['bundle_price'];
                    $discountForPair = $pairNormalPrice - $pairBundlePrice;

                    if ($discountForPair > 0) {
                        $halfPrice = $pairBundlePrice / 2;
                        $itemTotals[$driver['cart_id']] += $halfPrice;
                        $itemTotals[$partner['cart_id']] += $halfPrice;
                    } else {
                        $itemTotals[$driver['cart_id']] += $driver['normal_price'];
                        $itemTotals[$partner['cart_id']] += $partner['normal_price'];
                    }
                }
            }

            // Sisa yang tak dapat pasangan (Jomblo)
            foreach ($driversPool as $driver) {
                $itemTotals[$driver['cart_id']] += $driver['normal_price'];
            }
            foreach ($partnersPool as $partner) {
                $itemTotals[$partner['cart_id']] += $partner['normal_price'];
            }

            // SET TOTAL AKHIR BENAR-BENAR DARI HASIL KALKULASI BUNDLE
            $totalAmount = array_sum($itemTotals);

            // Hitung Ongkir Dasar
            // $totalQuantity = $cartItems->sum('quantity') ?: 1;
            // $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
            // $totalShippingCost = $baseShippingRate * $totalQuantity;

            // Hitung Ongkir Dasar
            $totalQuantity = $cartItems->sum('quantity') ?: 1;

            // 👇 Hapus perkalian dengan $totalQuantity agar ongkir tetap flat (sesuai API)
            $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

            // =========================================================================
            // 3. POTONG DISKON (10% + 10K)
            // =========================================================================
            $promoDiscountAmount = 0;
            if ($isClaimPromo) {
                if ($totalAmount < 50000) {
                    throw new \Exception('Minimum belanja Rp 50.000 untuk menggunakan promo ini.');
                }
                $productDiscount = floor($totalAmount * 0.10);
                $shippingSubsidy = min(10000, $totalShippingCost);
                $promoDiscountAmount = $productDiscount + $shippingSubsidy;
            }

            $totalAfterPromo = max(0, ($totalAmount + $totalShippingCost) - $promoDiscountAmount);

            // =========================================================================
            // 4. POTONG POIN LOYALTY
            // =========================================================================
            $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
            $pointsUsed = 0;
            $pointDiscountAmount = 0;

            if ($request->use_points > 0 && $lockedUser->is_membership) {
                $pointsUsed = min($request->use_points, $lockedUser->point);
                $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
                $pointDiscountAmount = $maxUsableDiscount;
                $pointsUsed = floor($maxUsableDiscount / 1000);

                if ($pointsUsed > 0) {
                    $lockedUser->decrement('point', $pointsUsed);
                }
            }

            // =========================================================================
            // 5. SIMPAN TRANSAKSI
            // =========================================================================
            $transaction = Transaction::create([
                'user_id' => $lockedUser->id,
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method,
                'shipping_cost' => $totalShippingCost,
                'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
                'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
                'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                'order_id' => $orderId,
                'total_amount' => $totalAmount, // INI SEKARANG MENGANDUNG DISKON BUNDLE!
                'status' => 'pending',
                'point' => $earnedPoints,
                'points_used' => $pointsUsed,
                'promo_code' => $appliedPromoCode,
                'promo_discount' => $promoDiscountAmount,
            ]);

            // =========================================================================
            // 6. POTONG STOK & SIMPAN DETAIL TRANSAKSI
            // =========================================================================
            $xenditItems = [];

            foreach ($cartItems as $item) {
                $product = Product::find($item->product_id); // Sudah dilock di atas

                // Ambil harga yang sudah disunat Bundle dari array $itemTotals
                $calculatedGross = $itemTotals[$item->id] ?? 0;
                $unitPrice = $item->quantity > 0 ? ($calculatedGross / $item->quantity) : 0;

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $unitPrice, // TERSIMPAN BENAR DI DATABASE
                    'color' => $item->color,
                ]);

                // Potong stok legacy/batch logic (disederhanakan)
                $product->decrement('stock', $item->quantity);

                // LOW STOCK ALERT
                if ($product->stock <= 5) {
                    try {
                        Mail::to('gycora.essence@gmail.com')->queue(new LowStockAlertMail($product));
                    } catch (\Exception $e) {}
                }
            }

            // 👇 TAMBAHKAN KODE INI UNTUK MENGHAPUS KERANJANG 👇
            Cart::where('user_id', $lockedUser->id)
                ->whereIn('id', $request->cart_ids)
                ->delete();
            // 👆 ============================================== 👆

            // 👇 [BARU] PANGGIL FUNGSI KIRIM EVENT CAPI FACEBOOK 👇
            // Kita panggil fungsi helper yang akan dibuat di bawah
            $this->sendMetaConversionApiEvent($transaction, $user, $cartItems, $totalAmount, $itemTotals);

            return [
                'transaction' => $transaction,
                'totalAmount' => $totalAmount,
                'totalShippingCost' => $totalShippingCost,
                'pointDiscountAmount' => $pointDiscountAmount,
                'pointsUsed' => $pointsUsed,
                'totalQuantity' => $totalQuantity,
                'promoCode' => $appliedPromoCode,
                'promoDiscountAmount' => $promoDiscountAmount,
                'promoType' => $promoType,
            ];
        });

        // PANGGIL PAYMENT GATEWAY DI LUAR DB TRANSACTION (MENGHINDARI DEADLOCK JIKA API LAMBAT)
        try {
            $transactionController = app(PaymentController::class);
            // Lempar ke PaymentController untuk digenerate Invoicenya
            $request->merge([
                'transaction_id' => $transactionData['transaction']->id,
                'currency' => 'IDR' // Default, akan dioverride frontend jika multicurrency
            ]);

            return $transactionController->createInvoice($request);
        } catch (\Exception $e) {
            Log::error('Invoice Creation Failed: '.$e->getMessage());
            app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);
            return response()->json(['message' => 'Payment gateway error. Please try again.'], 500);
        }
    }

    // SISA KODE (INDEX, CANCEL, DLL) TETAP SAMA...
    public function index(Request $request)
    {
        $transactions = Transaction::with(['details.product', 'payment', 'address'])->where('user_id', $request->user()->id)->latest()->get();
        return response()->json($transactions);
    }
    public function allTransactions()
    {
        $transactions = Transaction::with(['user', 'details.product', 'address'])->latest()->get();
        return response()->json($transactions);
    }
    public function cancelOrder(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);
        if (! in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) { return response()->json(['message' => 'Cannot cancel this order.'], 400); }
        if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && ! empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');
                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        return response()->json(['message' => 'Cannot cancel: The package is already being processed by the courier.'], 400);
                    }
                    Http::withHeaders(['Authorization' => config('services.biteship.api_key')])->delete('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
                }
            } catch (\Exception $e) {}

            try {
                $transaction->load('payment');
                if ($transaction->payment && $transaction->payment->external_id) {
                    $invoiceApi = new InvoiceApi;
                    $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);
                    if (! empty($invoices) && count($invoices) > 0) {
                        $xenditInvoiceId = $invoices[0]['id'];
                        $refundApi = new RefundApi;
                        $refundRequest = new CreateRefund([
                            'invoice_id' => $xenditInvoiceId, 'reason' => 'REQUESTED_BY_CUSTOMER', 'amount' => (int) $transaction->total_amount, 'metadata' => ['order_id' => $transaction->order_id]
                        ]);
                        $refundApi->createRefund(null, null, $refundRequest);
                    }
                }
            } catch (\Exception $e) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) { $this->restoreProductStock($detail->product_id, $detail->quantity); }
                });
                return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
            }
        }

        DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);
            if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
                $lockedTransaction->update(['status' => 'cancelled', 'shipping_status' => 'cancelled']);
                if ($lockedTransaction->points_used > 0) { $lockedTransaction->user->increment('point', $lockedTransaction->points_used); }
                if ($lockedTransaction->promo_code) {
                    PromoClaim::where('email', $lockedTransaction->user->email)->where('promo_code', $lockedTransaction->promo_code)->update(['is_used' => false, 'used_at' => null]);
                }
                if ($lockedTransaction->payment) { $lockedTransaction->payment->update(['status' => 'EXPIRED']); }
                foreach ($lockedTransaction->details as $detail) { $this->restoreProductStock($detail->product_id, $detail->quantity); }
            }
        });
        Cache::flush();
        return response()->json(['message' => 'Order cancelled successfully']);
    }
    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);
        if ($transaction->status !== 'processing') { return response()->json(['message' => 'Order cannot be completed yet.'], 400); }
        $transaction->update(['status' => 'completed']);
        $transaction->user->refresh();
        if ($transaction->point > 0 && $transaction->user->is_membership) { $transaction->user->increment('point', $transaction->point); }
        return response()->json(['message' => 'Order completed!']);
    }

    public function requestRefund(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        // Validasi: Refund hanya bisa diajukan saat pesanan selesai atau gagal kirim
        if (! in_array($transaction->status, ['completed', 'shipping_failed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        // [BARU] Validasi input text dan file bukti (gambar atau video)
        $request->validate([
            'reason' => 'required|string|max:1000',
            'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240', // Max 10MB
        ]);

        try {
            // [BARU] Upload file ke AWS S3
            $file = $request->file('proof_file');
            $path = $file->store('refund_proofs', [
                'disk' => 's3',
                'visibility' => 'public',
            ]);
            $proofUrl = Storage::disk('s3')->url($path);

            // Update transaksi
            $transaction->update([
                'status' => 'refund_requested',
                'refund_reason' => $request->reason,
                'refund_proof_url' => $proofUrl,
            ]);

            return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

        } catch (\Exception $e) {
            Log::error('Failed to upload refund proof: '.$e->getMessage());

            return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
        }
    }

    // User klik "Refund Now" setelah disetujui admin
    public function processRefundUser(Request $request, $id)
    {
        // 1. Ambil data transaksi (Tanpa Lock terlebih dahulu)
        $transaction = Transaction::with('payment')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // =========================================================================
        // [PERBAIKAN] ATOMIC STATE TRANSITION (Pencegah Double Refund)
        // Kita paksa ubah statusnya di database SEBELUM memanggil API Xendit.
        // Jika ada 2 request masuk bersamaan, request kedua akan menghasilkan $locked = 0 (Gagal)
        // =========================================================================
        $locked = Transaction::where('id', $id)
            ->where('status', 'refund_approved')
            ->update(['status' => 'refund_processing']); // Status sementara

        if (! $locked) {
            return response()->json(['message' => 'Refund is already being processed or not valid.'], 400);
        }

        if (! $transaction->payment) {
            // Rollback status karena gagal
            $transaction->update(['status' => 'refund_approved']);

            return response()->json(['message' => 'Payment data not found.'], 404);
        }

        // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR ---
        if ($transaction->shipping_method === 'biteship' && ! empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        // Rollback status karena kurir sudah jalan
                        $transaction->update(['status' => 'refund_approved']);

                        return response()->json([
                            'message' => 'Cannot process refund: The package is already in transit or has issues. Please contact logistics.',
                        ], 400);
                    }

                    // JIKA AMAN, BATALKAN KURIR
                    if (! in_array($biteshipStatus, ['cancelled'])) {
                        $cancelRes = Http::withHeaders([
                            'Authorization' => config('services.biteship.api_key'),
                        ])->delete('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                        $cancelData = $cancelRes->json();
                        if (isset($cancelData['success']) && $cancelData['success'] === false) {
                            $transaction->update(['status' => 'refund_approved']); // Rollback

                            return response()->json([
                                'message' => 'Failed to cancel courier. Refund aborted to prevent loss.',
                            ], 400);
                        }
                    }
                }
            } catch (\Exception $e) {
                $transaction->update(['status' => 'refund_approved']); // Rollback
                Log::error('Biteship Pre-Check Error: '.$e->getMessage());

                return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
            }
        }

        // --- EKSEKUSI REFUND KE XENDIT ---
        try {
            $invoiceApi = new InvoiceApi;
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception('Invoice not found in Xendit.');
            }

            $xenditInvoiceId = $invoices[0]['id'];
            $refundApi = new RefundApi;

            $refundRequest = new CreateRefund([
                'invoice_id' => $xenditInvoiceId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => ['order_id' => $transaction->order_id],
            ]);

            $refundApi->createRefund(null, null, $refundRequest);

            // Jika Xendit sukses, update ke status Akhir (Refunded)
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }

                // // Pengembalian poin yang dipakai ada di Fix Bencana 2 di bawah

                // foreach ($transaction->details as $detail) {
                //     $this->restoreProductStock($detail->product_id, $detail->quantity);
                // }

                // [PERBAIKAN MUTLAK: ANTI DOUBLE RESTOCK]
                // Hanya kembalikan stok jika belum pernah dibatalkan sebelumnya
                // Jika pesanan gagal dari processing langsung refund, kita restore.
                // TAPI jika sebelumnya sudah refund_manual_required/cancelled, stok SUDAH KEMBALI.
                $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned'];

                // Gunakan status dari instance sebelum diupdate (karena di atas sudah diupdate ke 'refunded')
                $originalStatus = $transaction->getOriginal('status');

                if (! in_array($originalStatus, $statusesThatAlreadyRestoredStock)) {
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            });

            // Cache::tags(['catalog'])->flush();
            Cache::flush();

            return response()->json([
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic',
            ]);
        } catch (XenditSdkException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                // Cache::tags(['catalog'])->flush();
                Cache::flush();

                return response()->json([
                    'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
                    'code' => 'MANUAL_REFUND_NEEDED',
                ], 200);
            }

            $transaction->update(['status' => 'refund_approved']); // Rollback

            return response()->json(['message' => 'Xendit Refund Failed: '.$errorMessage], 422);
        } catch (\Exception $e) {
            $transaction->update(['status' => 'refund_approved']); // Rollback

            return response()->json(['message' => 'Refund Error: '.$e->getMessage()], 500);
        }
    }

    public function approveRefund($id)
    {
        // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_approved']);

        // [BARU] Kirim notifikasi email ke user
        try {
            Mail::to($transaction->user->email)->queue(new RefundResultMail($transaction, 'approve'));
        } catch (\Exception $e) {
            // Jika gagal kirim email, jangan hentikan proses approve
            Log::error("Gagal kirim email Approve Refund ke {$transaction->user->email}: ".$e->getMessage());
        }

        return response()->json(['message' => 'Refund request approved. Email sent to customer.']);
    }

    // public function rejectRefund($id)
    // {
    //     $transaction = Transaction::findOrFail($id);
    //     if ($transaction->status !== 'refund_requested') {
    //         return response()->json(['message' => 'Invalid status'], 400);
    //     }

    //     $transaction->update(['status' => 'refund_rejected']);
    //     return response()->json(['message' => 'Refund request rejected.']);
    // }

    public function rejectRefund($id)
    {
        // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_rejected']);

        // [BARU] Kirim notifikasi email ke user
        try {
            Mail::to($transaction->user->email)->queue(new RefundResultMail($transaction, 'reject'));
        } catch (\Exception $e) {
            // Jika gagal kirim email, jangan hentikan proses reject
            Log::error("Gagal kirim email Reject Refund ke {$transaction->user->email}: ".$e->getMessage());
        }

        return response()->json(['message' => 'Refund request rejected. Email sent to customer.']);
    }

    // Show single transaction
    public function show($id)
    {
        return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
    }

    public function adminShow($id)
    {
        // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
        $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function salesReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $search = $request->query('search');

        $query = TransactionDetail::query()
            ->select(
                'products.id',
                'products.sku',
                'products.name',
                'products.image_url',
                'categories.name as category_name',
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('transactions.status', ['completed', 'refund_rejected']);

        if ($month && $year) {
            $query->whereMonth('transactions.created_at', $month)
                ->whereYear('transactions.created_at', $year);
        } elseif ($year) {
            $query->whereYear('transactions.created_at', $year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%");
            });
        }

        // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
        $report = $query->groupBy('products.id', 'products.sku', 'products.name', 'products.image_url', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report, // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
        ]);
    }

    public function trackOrder($id)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

        // [PERBAIKAN] Validasi menggunakan biteship_order_id
        if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
        }
    }

    public function bulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
        foreach ($transactions as $transaction) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending']; // Fallback jika belum teralokasi
                }
            } catch (\Exception $e) {
                // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
        return response()->json($trackingData);
    }

    // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
    public function adminBulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
        $transactions = Transaction::whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        foreach ($transactions as $transaction) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];
                }
            } catch (\Exception $e) {
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        return response()->json($trackingData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id); // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        if (! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
        }

        // Ambil query parameter dari Vue (insurance_shown, dll)
        $queryString = http_build_query($request->all());

        // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
        $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

        try {
            // Tembak URL label Biteship dengan API Key kita
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get($biteshipUrl);

            // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-'.$transaction->order_id.'.pdf"');
            }

            return response()->json(['message' => 'Gagal mengambil resi dari Biteship: '.$response->body()], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem: '.$e->getMessage()], 500);
        }
    }

    public function retryShipping($id)
    {
        $transaction = Transaction::with(['details.product', 'address', 'user'])->findOrFail($id);

        if ($transaction->shipping_method !== 'biteship') {
            return response()->json(['message' => 'Metode pengiriman bukan Biteship, tidak bisa di-request ulang.'], 400);
        }

        // Pastikan kita tidak menimpa resi yang sebenarnya sudah sukses
        if ($transaction->biteship_order_id && !str_starts_with($transaction->tracking_number, 'API ERR') && !str_starts_with($transaction->tracking_number, 'SYS ERR')) {
            return response()->json(['message' => 'Pesanan ini sudah memiliki nomor resi yang valid.'], 400);
        }

        try {
            // Panggil ulang service pembuat pesanan Biteship
            $biteship = new BiteshipService;
            $order = $biteship->createOrder($transaction);

            if (isset($order['id'])) {
                $transaction->update([
                    'biteship_order_id' => $order['id'],
                    'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
                    'shipping_status' => strtolower($order['status'] ?? 'pending'),
                ]);

                return response()->json([
                    'message' => 'Berhasil! Pesanan sukses dikirim ulang ke Biteship.',
                    'tracking_number' => $transaction->tracking_number
                ]);
            } else {
                $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');

                $transaction->update([
                    'tracking_number' => 'API ERR: '.substr($errorMsg, 0, 200),
                    'shipping_status' => 'error',
                ]);

                Log::error('Biteship Retry Failed: '.json_encode($order));
                return response()->json(['message' => 'Gagal request ke Biteship: ' . $errorMsg], 400);
            }
        } catch (\Exception $e) {
            $transaction->update([
                'tracking_number' => 'SYS ERR: '.substr($e->getMessage(), 0, 200),
                'shipping_status' => 'error',
            ]);

            return response()->json(['message' => 'Terjadi kesalahan sistem internal: ' . $e->getMessage()], 500);
        }
    }

    public function biteshipCallback(Request $request)
    {
        // Validasi signature (Opsional tapi disarankan)
        // $signature = $request->header('biteship-signature');
        // $secret = config('services.biteship.webhook_secret'); // Tambahkan di config/services.php dan .env

        // if ($signature !== $secret) {
        //     Log::critical('Fake Biteship Webhook Detected!', $request->all());

        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        $biteshipOrderId = $request->input('order_id');
        $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
        $waybill = $request->input('courier_waybill_id');

        \Log::info('Biteship Webhook Received: ', $request->all());

        // [PERBAIKAN MUTLAK: DB TRANSACTION & LOCKING]
        return DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {

            // $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();
            // Kunci baris ini agar webhook yang datang bersamaan harus antre!
            $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return response()->json(['message' => 'Transaction not found'], 200);
            }

            // Mencegah proses ulang jika status sudah 'completed'
            if ($transaction->status === 'completed' && $status === 'delivered') {
                return response()->json(['message' => 'Already completed'], 200);
            }

            // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
            $updates = ['shipping_status' => $status];

            // 1. Update Resi jika baru turun
            if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
                $updates['tracking_number'] = $waybill;
            }

            // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
            if ($status === 'delivered' && $transaction->status === 'processing') {
                $updates['status'] = 'completed';

                // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
                $transaction->update($updates);

                // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
                $this->checkAndAssignMembership($transaction->user);

                // Refresh data user
                $transaction->user->refresh();

                // Tambah poin user jika dia member dan transaksi punya poin
                if ($transaction->point > 0 && $transaction->user->is_membership) {
                    $transaction->user->increment('point', $transaction->point);
                }

                return response()->json(['message' => 'Webhook processed and membership checked']);
            }

            // 3. Jika logistik membatalkan pengiriman SEPIHAK
            if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
                $updates['status'] = 'refund_manual_required';
                $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
                \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
            }

            if ($status === 'disposed' && $transaction->status === 'processing') {
                $updates['status'] = 'shipping_failed';
                $updates['tracking_number'] = 'Shipping Failed';
                \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
            }

            if ($status === 'returned' && $transaction->status === 'processing') {
                $updates['status'] = 'returned';
                $updates['tracking_number'] = 'Shipping Returned';
                \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
            }

            // Eksekusi semua update ke database dalam 1 query
            $transaction->update($updates);

            // 👇 [BARU] TRIGGER PENGIRIMAN EMAIL NOTIFIKASI SECARA BACKGROUND 👇
            SendShippingUpdateJob::dispatch($transaction->id, $status);
            // 👆 ========================================================== 👆

            return response()->json(['message' => 'Webhook processed successfully']);
        });
    }

    // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
    private function checkAndAssignMembership($user)
    {
        // Jika user sudah member, tidak perlu cek lagi
        if ($user->is_membership) {
            return;
        }

        // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

        // Jika total belanja >= 100.000, jadikan member
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }

    // =========================================================================
    // 👇 [BARU] FUNGSI HELPER UNTUK META CONVERSION API (CAPI) 👇
    // =========================================================================
    private function sendMetaConversionApiEvent($transaction, $user, $cartItems, $totalAmount, $itemTotals)
    {
        try {
            $pixelId = env('META_PIXEL_ID');
            $accessToken = env('META_CAPI_ACCESS_TOKEN');

            // Jika token/pixel ID tidak ada, jangan paksakan (misal di local)
            if (!$pixelId || !$accessToken) {
                return;
            }

            // 1. Susun Data Pengguna (Harus di-hash SHA256 sesuai aturan Facebook)
            $hashedEmail = hash('sha256', strtolower(trim($user->email)));
            $hashedPhone = $user->phone ? hash('sha256', preg_replace('/[^0-9]/', '', $user->phone)) : null;

            $userData = ['em' => $hashedEmail];
            if ($hashedPhone) {
                $userData['ph'] = $hashedPhone;
            }

            // 2. Susun Data Konten (Produk)
            $contents = [];
            foreach ($cartItems as $item) {
                $product = $item->product;
                if ($product) {
                    $itemTotal = $itemTotals[$item->id] ?? ($product->price * $item->quantity);
                    $unitPrice = $item->quantity > 0 ? ($itemTotal / $item->quantity) : 0;

                    $contents[] = [
                        'id' => (string) $product->id,
                        'quantity' => $item->quantity,
                        'item_price' => $unitPrice,
                    ];
                }
            }

            // 3. Susun Payload untuk Graph API Facebook
            $payload = [
                'data' => [
                    [
                        'event_name' => 'Purchase', // Nama event wajib untuk CAPI
                        'event_time' => time(),
                        'action_source' => 'website',
                        'user_data' => $userData,
                        'custom_data' => [
                            'currency' => 'IDR', // Asumsikan base currency
                            'value' => (float) $totalAmount,
                            'contents' => $contents,
                            'content_type' => 'product',
                            'order_id' => $transaction->order_id,
                        ],
                    ]
                ]
            ];

            // 4. Kirim Request ke Server Meta (Background Process)
            // Timeout disetel kecil agar tidak menahan proses checkout jika Meta sedang lambat
            Http::timeout(5)->post("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token={$accessToken}", $payload);

        } catch (\Exception $e) {
            // Kita log error-nya, tapi JANGAN gagalkan transaksi pelanggan!
            \Illuminate\Support\Facades\Log::warning('Meta CAPI Error: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // 👇 FUNGSI BARU UNTUK ADMIN MENGHAPUS TRANSAKSI PERMANEN 👇
    // =====================================================================
    public function forceDeleteTransaction(Request $request, $id)
    {
        $transaction = Transaction::with(['details', 'payment'])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        // Mulai transaksi database agar penghapusan dan pengembalian stok konsisten
        DB::transaction(function () use ($transaction) {

            // 1. KEMBALIKAN STOK BARANG (Jika statusnya belum pernah dikembalikan)
            $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

            if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                foreach ($transaction->details as $detail) {
                    $this->restoreProductStock($detail->product_id, $detail->quantity);
                }
            }

            // 2. KEMBALIKAN POIN (Opsional: Jika ingin poin uji coba kembali)
            if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                $transaction->user->increment('point', $transaction->points_used);
            }

            // 3. HAPUS DATA PEMBAYARAN TERKAIT
            if ($transaction->payment) {
                $transaction->payment->delete();
            }

            // 4. HAPUS DETAIL TRANSAKSI & BERSIHKAN CACHE
            foreach ($transaction->details as $detail) {
                Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
                $detail->delete();
            }

            // 5. HAPUS TRANSAKSI UTAMA
            $transaction->delete();
        });

        // Bersihkan cache secara menyeluruh
        Cache::flush();

        return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    }
}
