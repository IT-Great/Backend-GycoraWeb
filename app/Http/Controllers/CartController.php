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
    // HELPER: SINKRONISASI HARGA DINAMIS (UNIVERSAL BUNDLE POOL)
    // =========================================================================
    private function syncCartPrices($user)
    {
        $carts = $user->carts()->with('product')->get();
        $totalCartQty = $carts->sum('quantity');
        $isReseller = $user->usertype === 'reseller';

        $bundlePool = [];
        $cartUpdates = [];

        foreach ($carts as $cart) {
            // Inisialisasi awal nilai 0 agar update += berjalan mulus
            if (!isset($cartUpdates[$cart->id])) {
                $cartUpdates[$cart->id] = 0;
            }

            $priceToUse = $cart->product->price;

            // Diskon Normal
            if ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
                $priceToUse = $cart->product->discount_price;
            }

            // Reseller bypass bundle
            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $cartUpdates[$cart->id] += $cart->product->wholesale_price * $cart->quantity;
                continue;
            }

            // Validasi keabsahan status bundle anti-error
            $isBundleActiveFlag = in_array($cart->product->is_bundle_active, [1, '1', true], true);
            
            $dateStr = $cart->product->bundle_end_date;
            $isValidDate = true;
            if (!empty($dateStr) && $dateStr !== '0000-00-00 00:00:00') {
                try {
                    $isValidDate = Carbon::parse($dateStr)->isFuture();
                } catch (\Exception $e) {
                    $isValidDate = false; // Tangani jika string date rusak di DB
                }
            }

            $isBundleValid = $isBundleActiveFlag && $isValidDate && $cart->product->bundle_price > 0;

            if ($isBundleValid) {
                // Sebarkan qty produk ke dalam satu kolam universal
                for ($i = 0; $i < $cart->quantity; $i++) {
                    $bundlePool[] = [
                        'cart_id'      => $cart->id,
                        'normal_price' => $priceToUse,
                        'bundle_price' => $cart->product->bundle_price
                    ];
                }
            } else {
                $cartUpdates[$cart->id] += $priceToUse * $cart->quantity;
            }
        }

        // Proses pencarian Pasangan dari kolam universal
        if (!empty($bundlePool)) {
            // Urutkan dari harga bundle terbesar agar user dapat diskon maksimal
            usort($bundlePool, function($a, $b) {
                return $b['bundle_price'] <=> $a['bundle_price'];
            });

            $totalItems = count($bundlePool);
            $pairs = floor($totalItems / 2);

            // Kawinkan pasangan (apapun tipe itemnya asalkan dia is_bundle_active)
            for ($i = 0; $i < $pairs; $i++) {
                $item1 = $bundlePool[$i * 2];
                $item2 = $bundlePool[$i * 2 + 1];

                // Ambil patokan harga bundle tertinggi di antara keduanya
                $pairPrice = max($item1['bundle_price'], $item2['bundle_price']);
                $halfPrice = $pairPrice / 2;

                $cartUpdates[$item1['cart_id']] += $halfPrice;
                $cartUpdates[$item2['cart_id']] += $halfPrice;
            }

            // Bayar harga normal untuk produk jomblo / sisa yang tak dapat pasangan
            for ($i = $pairs * 2; $i < $totalItems; $i++) {
                $unpairedItem = $bundlePool[$i];
                $cartUpdates[$unpairedItem['cart_id']] += $unpairedItem['normal_price'];
            }
        }

        // Simpan pembaruan total harga ke DB
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