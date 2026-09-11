<?php

// namespace App\Http\Controllers;

// use Carbon\Carbon;
// use App\Models\Cart;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use App\Http\Controllers\Controller;

// class CartController extends Controller
// {
//     // =========================================================================
//     // HELPER: SINKRONISASI HARGA DINAMIS (DRIVER - PARTNER BUNDLE SYSTEM)
//     // =========================================================================
//     private function syncCartPrices($user)
//     {
//         // 👇 [PERBAIKAN] Tambahkan with('product.category') agar kita bisa membaca kode kategori
//         $carts = $user->carts()->with(['product.category'])->get();
//         $totalCartQty = $carts->sum('quantity');
//         $isReseller = $user->usertype === 'reseller';

//         $driversPool = [];  // Kolam untuk Produk EGB (Penentu Harga Bundle)
//         $partnersPool = []; // Kolam untuk Semua Produk Non-EGB (Pasif)
//         $cartUpdates = [];

//         foreach ($carts as $cart) {
//             $cartUpdates[$cart->id] = 0; // Reset nilai awal
//             $priceToUse = $cart->product->price;

//             // Harga Diskon Reguler
//             if ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
//                 $priceToUse = $cart->product->discount_price;
//             }

//             // Jika Reseller valid (Bypass urusan bundle)
//             if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
//                 $cartUpdates[$cart->id] = $cart->product->wholesale_price * $cart->quantity;
//                 continue;
//             }

//             // Identifikasi Kategori via SKU
//             $sku = strtoupper($cart->product->sku ?? '');
//             $isEGB = str_starts_with($sku, 'EGB');

//             // 👇 [PERBAIKAN LOGIKA BUNDLE] Termasuk kategori "BN-01"
//             $isBundleActiveFlag = filter_var($cart->product->is_bundle_active, FILTER_VALIDATE_BOOLEAN);
//             $isCategoryBundle = ($cart->product->category && $cart->product->category->code === 'BN-01');
//             $isBundleValid = $isBundleActiveFlag || $isCategoryBundle;

//             $dateStr = $cart->product->bundle_end_date;
//             $isValidDate = true;

//             if (!empty($dateStr) && $dateStr !== '0000-00-00 00:00:00') {
//                 try {
//                     $isValidDate = Carbon::parse($dateStr)->isFuture();
//                 } catch (\Exception $e) {
//                     $isValidDate = false;
//                 }
//             }

//             // Syarat mutlak menjadi Driver: Harus EGB, Bundle Aktif/BN-01, Tanggal Valid, Punya Harga Bundle
//             $isDriver = $isEGB && $isBundleValid && $isValidDate && $cart->product->bundle_price > 0;

//             // Pecah qty menjadi unit tunggal ke dalam kolam masing-masing
//             for ($i = 0; $i < $cart->quantity; $i++) {
//                 $itemData = [
//                     'cart_id'      => $cart->id,
//                     'normal_price' => $priceToUse,
//                     'bundle_price' => $cart->product->bundle_price // Hanya berguna bagi Driver
//                 ];

//                 if ($isDriver) {
//                     $driversPool[] = $itemData;
//                 } elseif (!$isEGB) {
//                     // Barang Non-EGB mutlak menjadi Partner pasif
//                     $partnersPool[] = $itemData;
//                 } else {
//                     // Jika dia EGB tapi bundle mati/expired, langsung masuk tagihan normal
//                     $cartUpdates[$cart->id] += $priceToUse;
//                 }
//             }
//         }

//         // TAHAP PENJODOHAN (CROSS-CATEGORY PAIRING)
//         if (count($driversPool) > 0 && count($partnersPool) > 0) {
//             usort($driversPool, function($a, $b) {
//                 return $b['bundle_price'] <=> $a['bundle_price'];
//             });

//             while (count($driversPool) > 0 && count($partnersPool) > 0) {
//                 $driver = array_shift($driversPool);
//                 $partner = array_shift($partnersPool);

//                 $pairNormalPrice = $driver['normal_price'] + $partner['normal_price'];
//                 $pairBundlePrice = $driver['bundle_price'];

//                 $discountForPair = $pairNormalPrice - $pairBundlePrice;

//                 if ($discountForPair > 0) {
//                     $cartUpdates[$driver['cart_id']] += ($driver['normal_price'] - $discountForPair);
//                     $cartUpdates[$partner['cart_id']] += $partner['normal_price'];
//                 } else {
//                     $cartUpdates[$driver['cart_id']] += $driver['normal_price'];
//                     $cartUpdates[$partner['cart_id']] += $partner['normal_price'];
//                 }
//             }
//         }

//         // Sisa Jomblo (Driver/Partner yang tidak kebagian pasangan) bayar normal
//         foreach ($driversPool as $driver) {
//             $cartUpdates[$driver['cart_id']] += $driver['normal_price'];
//         }
//         foreach ($partnersPool as $partner) {
//             $cartUpdates[$partner['cart_id']] += $partner['normal_price'];
//         }

//         // Eksekusi Update ke Database
//         foreach ($cartUpdates as $cartId => $grossAmount) {
//             if ($grossAmount > 0) {
//                 Cart::where('id', $cartId)->update(['gross_amount' => $grossAmount]);
//             }
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
//                     'gross_amount' => 0,
//                 ]);
//             } else {
//                 $user->carts()->create([
//                     'product_id'   => $product->id,
//                     'color'        => $request->color,
//                     'quantity'     => $newQuantity,
//                     'gross_amount' => 0,
//                 ]);
//             }
//         });

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
//             'gross_amount' => 0,
//         ]);

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

//         $this->syncCartPrices($user);

//         return response()->json([
//             'message' => 'Item removed'
//         ]);
//     }
// }

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class CartController extends Controller
{
    // =========================================================================
    // HELPER: SINKRONISASI HARGA DINAMIS (DRIVER - PARTNER BUNDLE SYSTEM)
    // =========================================================================
    private function syncCartPrices($user)
    {
        $carts = $user->carts()->with(['product.category'])->get();
        $totalCartQty = $carts->sum('quantity');
        $isReseller = $user->usertype === 'reseller';

        $driversPool = [];  
        $partnersPool = []; 
        $cartUpdates = [];

        foreach ($carts as $cart) {
            $cartUpdates[$cart->id] = 0; 
            $priceToUse = $cart->product->price;

            if ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
                $priceToUse = $cart->product->discount_price;
            }

            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $cartUpdates[$cart->id] = $cart->product->wholesale_price * $cart->quantity;
                continue;
            }

            $sku = strtoupper($cart->product->sku ?? '');
            $isEGB = str_starts_with($sku, 'EGB');

            $isBundleActiveFlag = filter_var($cart->product->is_bundle_active, FILTER_VALIDATE_BOOLEAN);
            $isCategoryBundle = ($cart->product->category && $cart->product->category->code === 'BN-01');
            $isBundleValid = $isBundleActiveFlag || $isCategoryBundle;

            $dateStr = $cart->product->bundle_end_date;
            $isValidDate = true;

            if (!empty($dateStr) && $dateStr !== '0000-00-00 00:00:00') {
                try {
                    $isValidDate = Carbon::parse($dateStr)->isFuture();
                } catch (\Exception $e) {
                    $isValidDate = false;
                }
            }

            $isDriver = $isEGB && $isBundleValid && $isValidDate && $cart->product->bundle_price > 0;

            for ($i = 0; $i < $cart->quantity; $i++) {
                $itemData = [
                    'cart_id'      => $cart->id,
                    'normal_price' => $priceToUse,
                    'bundle_price' => $cart->product->bundle_price 
                ];

                if ($isDriver) {
                    $driversPool[] = $itemData;
                } elseif (!$isEGB) {
                    $partnersPool[] = $itemData;
                } else {
                    $cartUpdates[$cart->id] += $priceToUse;
                }
            }
        }

        // TAHAP PENJODOHAN (CROSS-CATEGORY PAIRING)
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
                    // 👇 [PERBAIKAN FATAL 1] Disamakan persis dengan TransactionController
                    $halfPrice = floor($driver['bundle_price'] / 2);
                    $remainder = $driver['bundle_price'] % 2;

                    $cartUpdates[$driver['cart_id']] += ($halfPrice + $remainder);
                    $cartUpdates[$partner['cart_id']] += $halfPrice;
                } else {
                    $cartUpdates[$driver['cart_id']] += $driver['normal_price'];
                    $cartUpdates[$partner['cart_id']] += $partner['normal_price'];
                }
            }
        }

        foreach ($driversPool as $driver) {
            $cartUpdates[$driver['cart_id']] += $driver['normal_price'];
        }
        foreach ($partnersPool as $partner) {
            $cartUpdates[$partner['cart_id']] += $partner['normal_price'];
        }

        foreach ($cartUpdates as $cartId => $grossAmount) {
            // 👇 [PERBAIKAN FATAL 3] Izinkan 0 untuk item gratis/100% diskon
            if ($grossAmount >= 0) { 
                Cart::where('id', $cartId)->update(['gross_amount' => $grossAmount]);
            }
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

        // 👇 [PERBAIKAN FATAL 2] Bungkus seluruh logika read & write dalam DB Transaction & Lock
        return DB::transaction(function () use ($validated, $user, $request) {
            // Lock tabel user agar request yang datang bersamaan (Spam Click) diproses antre (berurutan)
            User::where('id', $user->id)->lockForUpdate()->first();

            $product = Product::findOrFail($validated['product_id']);

            $existingCart = Cart::where('user_id', $user->id)
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

            if ($existingCart) {
                $existingCart->update([
                    'quantity'     => $newQuantity,
                    'gross_amount' => 0, // Akan dihitung ulang oleh syncCartPrices
                ]);
            } else {
                $user->carts()->create([
                    'product_id'   => $product->id,
                    'color'        => $request->color,
                    'quantity'     => $newQuantity,
                    'gross_amount' => 0,
                ]);
            }

            // Sync harga di dalam transaction yang sama
            $this->syncCartPrices($user);

            $latestCart = Cart::where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('color', $request->color)
                ->first();

            return response()->json([
                'message' => 'Added to cart successfully',
                'cart_id' => $latestCart->id,
            ], 200);
        });
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();

        // 👇 Amankan juga update qty keranjang dengan Lock
        return DB::transaction(function () use ($validated, $user, $id) {
            User::where('id', $user->id)->lockForUpdate()->first();

            $cart = Cart::where('user_id', $user->id)->findOrFail($id);
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
        });
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        
        DB::transaction(function () use ($user, $id) {
            User::where('id', $user->id)->lockForUpdate()->first();
            $cart = Cart::where('user_id', $user->id)->findOrFail($id);
            $cart->delete();

            $this->syncCartPrices($user);
        });

        return response()->json([
            'message' => 'Item removed'
        ]);
    }
}