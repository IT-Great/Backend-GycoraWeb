<?php

// namespace App\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Models\Cart;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class CartController extends Controller
// {
//     // =========================================================================
//     // HELPER: SINKRONISASI HARGA DINAMIS BERDASARKAN MOQ
//     // =========================================================================
//     private function syncCartPrices($user)
//     {
//         // 1. Ambil seluruh isi keranjang user beserta relasi produknya
//         $carts = $user->carts()->with('product')->get();

//         // 2. Hitung Total QTY yang ada di keranjang
//         $totalCartQty = $carts->sum('quantity');
//         $isReseller = $user->usertype === 'reseller';

//         // 3. Update kembali seluruh gross_amount di database agar akurat dengan UI
//         foreach ($carts as $cart) {
//             $priceToUse = $cart->product->price;

//             if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
//                 $priceToUse = $cart->product->wholesale_price;
//             } elseif ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
//                 $priceToUse = $cart->product->discount_price;
//             }

//             $cart->update([
//                 'gross_amount' => $priceToUse * $cart->quantity
//             ]);
//         }
//     }

//     public function index(Request $request)
//     {
//         $carts = $request->user()->carts()
//             ->with('product')
//             ->orderBy('id', 'desc')
//             ->get();

//         return response()->json($carts);
//     }

//     public function store(Request $request)
//     {
//         $validated = $request->validate([
//             'product_id' => 'required|exists:products,id',
//             'quantity'   => 'required|integer|min:1',
//             'color'      => 'nullable|string|max:50',
//         ]);

//         $user = $request->user();
//         $product = Product::findOrFail($validated['product_id']);

//         $existingCart = $user->carts()
//             ->where('product_id', $product->id)
//             ->where('color', $request->color)
//             ->first();

//         $newQuantity = $validated['quantity'];
//         if ($existingCart) {
//             $newQuantity += $existingCart->quantity;
//         }

//         if ($newQuantity > $product->stock) {
//             return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
//         }

//         DB::transaction(function () use ($existingCart, $user, $product, $newQuantity, $request) {
//             if ($existingCart) {
//                 $existingCart->update([
//                     'quantity'     => $newQuantity,
//                     'gross_amount' => 0, // Placeholder sementara
//                 ]);
//             } else {
//                 $user->carts()->create([
//                     'product_id'   => $product->id,
//                     'color'        => $request->color,
//                     'quantity'     => $newQuantity,
//                     'gross_amount' => 0, // Placeholder sementara
//                 ]);
//             }
//         });

//         // 👇 Panggil sinkronisasi setelah database disimpan
//         $this->syncCartPrices($user);

//         return response()->json([
//             'message' => 'Added to cart successfully',
//             'cart_id' => $existingCart ? $existingCart->id : $user->carts()->latest('id')->first()->id,
//         ], 200);
//     }

//     public function update(Request $request, $id)
//     {
//         $validated = $request->validate([
//             'quantity' => 'required|integer|min:1',
//         ]);

//         $user = $request->user();
//         $cart = $user->carts()->findOrFail($id);
//         $product = $cart->product;

//         if ($validated['quantity'] > $product->stock) {
//             return response()->json([
//                 'message' => 'Stock limited!'
//             ], 422);
//         }

//         $cart->update([
//             'quantity'     => $validated['quantity'],
//             'gross_amount' => 0, // Placeholder sementara
//         ]);

//         // 👇 Panggil sinkronisasi setelah database diupdate
//         $this->syncCartPrices($user);

//         return response()->json([
//             'message' => 'Cart updated successfully'
//         ]);
//     }

//     public function destroy(Request $request, $id)
//     {
//         $user = $request->user();
//         $cart = $user->carts()->findOrFail($id);

//         $cart->delete();

//         // 👇 Panggil sinkronisasi setelah item dihapus
//         $this->syncCartPrices($user);

//         return response()->json([
//             'message' => 'Item removed'
//         ]);
//     }
// }

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CartController extends Controller
{
    // =========================================================================
    // HELPER: SINKRONISASI HARGA DINAMIS (CROSS-CATEGORY BUNDLE SYSTEM)
    // =========================================================================
    private function syncCartPrices($user)
    {
        $carts = $user->carts()->with('product')->get();
        $totalCartQty = $carts->sum('quantity');
        $isReseller = $user->usertype === 'reseller';

        $drivers = [];  // Kolam untuk Produk EGB yang Bundle Aktif
        $partners = []; // Kolam untuk Semua Produk Non-EGB
        $cartUpdates = [];

        foreach ($carts as $cart) {
            // Inisialisasi awal nilai array
            if (!isset($cartUpdates[$cart->id])) {
                $cartUpdates[$cart->id] = 0;
            }

            $priceToUse = $cart->product->price;

            // Harga Diskon Reguler
            if ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
                $priceToUse = $cart->product->discount_price;
            }

            // Jika Reseller valid, bypass semua urusan promo bundle
            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $cartUpdates[$cart->id] += $cart->product->wholesale_price * $cart->quantity;
                continue;
            }

            // Identifikasi apakah barang ini EGB (Cross Category Check)
            $sku = strtoupper($cart->product->sku ?? '');
            $isEGB = str_starts_with($sku, 'EGB');

            // Cek keabsahan Bundle
            $isBundleActiveFlag = filter_var($cart->product->is_bundle_active, FILTER_VALIDATE_BOOLEAN);

            $dateStr = $cart->product->bundle_end_date;
            $isValidDate = true;
            if (!empty($dateStr) && $dateStr !== '0000-00-00 00:00:00') {
                try {
                    $isValidDate = Carbon::parse($dateStr)->isFuture();
                } catch (\Exception $e) {
                    $isValidDate = false;
                }
            }

            // Driver HANYA JIKA dia Bundle Aktif, Tgl Valid, Ada Harga, DAN dia adalah EGB
            $isDriver = $isBundleActiveFlag && $isValidDate && $cart->product->bundle_price > 0;

            // Pecah qty menjadi unit (1 barang = 1 baris di kolam)
            for ($i = 0; $i < $cart->quantity; $i++) {
                $itemData = [
                    'cart_id'      => $cart->id,
                    'normal_price' => $priceToUse,
                    'bundle_price' => $cart->product->bundle_price
                ];

                if ($isDriver) {
                    $drivers[] = $itemData;
                } elseif (!$isEGB) {
                    // Barang Non-EGB (Eco Serenity, dll) masuk ke kolam pasrah (Partners)
                    $partners[] = $itemData;
                }
            }

            // Semua cart awalnya diset ke harga normal/diskon per item
            $cartUpdates[$cart->id] += $priceToUse * $cart->quantity;
        }

        // TAHAP PENJODOHAN (PAIRING)
        if (count($drivers) > 0 && count($partners) > 0) {

            // Urutkan driver dari harga bundle yang paling tinggi untuk memprioritaskan diskon maksimal ke user
            usort($drivers, function($a, $b) {
                return $b['bundle_price'] <=> $a['bundle_price'];
            });

            foreach ($drivers as $driver) {
                if (count($partners) > 0) {
                    // Tarik 1 partner keluar dari kolam
                    $partner = array_shift($partners);

                    // Hitung total normal mereka jika tidak dibundle
                    $pairNormalPrice = $driver['normal_price'] + $partner['normal_price'];
                    $pairBundlePrice = $driver['bundle_price'];

                    // Hitung Diskon yang Dihasilkan
                    $discountForPair = $pairNormalPrice - $pairBundlePrice;

                    // Terapkan diskon hanya jika menguntungkan
                    if ($discountForPair > 0) {
                        // Potong langsung diskon tersebut dari total yang sudah diset di cartUpdates milik Driver
                        $cartUpdates[$driver['cart_id']] -= $discountForPair;
                    }
                }
            }
        }

        // Eksekusi Simpan Database
        foreach ($cartUpdates as $cartId => $grossAmount) {
            Cart::where('id', $cartId)->update(['gross_amount' => $grossAmount]);
        }
    }

    public function index(Request $request)
    {
        $carts = $request->user()->carts()
            ->with('product')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($carts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'color'      => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $product = Product::findOrFail($validated['product_id']);

        $existingCart = $user->carts()
            ->where('product_id', $product->id)
            ->where('color', $request->color)
            ->first();

        $newQuantity = $validated['quantity'];
        if ($existingCart) {
            $newQuantity += $existingCart->quantity;
        }

        if ($newQuantity > $product->stock) {
            return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
        }

        DB::transaction(function () use ($existingCart, $user, $product, $newQuantity, $request) {
            if ($existingCart) {
                $existingCart->update([
                    'quantity'     => $newQuantity,
                    'gross_amount' => 0,
                ]);
            } else {
                $user->carts()->create([
                    'product_id'   => $product->id,
                    'color'        => $request->color,
                    'quantity'     => $newQuantity,
                    'gross_amount' => 0,
                ]);
            }
        });

        $this->syncCartPrices($user);

        return response()->json([
            'message' => 'Added to cart successfully',
            'cart_id' => $existingCart ? $existingCart->id : $user->carts()->latest('id')->first()->id,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $cart = $user->carts()->findOrFail($id);
        $product = $cart->product;

        if ($validated['quantity'] > $product->stock) {
            return response()->json([
                'message' => 'Stock limited!'
            ], 422);
        }

        $cart->update([
            'quantity'     => $validated['quantity'],
            'gross_amount' => 0,
        ]);

        $this->syncCartPrices($user);

        return response()->json([
            'message' => 'Cart updated successfully'
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $cart = $user->carts()->findOrFail($id);

        $cart->delete();

        $this->syncCartPrices($user);

        return response()->json([
            'message' => 'Item removed'
        ]);
    }
}
