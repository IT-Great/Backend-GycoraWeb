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
    // HELPER: SINKRONISASI HARGA DINAMIS (MEMAKAI SISTEM PAIRING UNTUK BUNDLE)
    // =========================================================================
    private function syncCartPrices($user)
    {
        $carts = $user->carts()->with('product')->get();
        $totalCartQty = $carts->sum('quantity');
        $isReseller = $user->usertype === 'reseller';

        $bundlePool = [];
        $cartUpdates = []; // Simpan gross_amount akhir untuk tiap cart id

        // 1. Loop pertama: Filter harga normal/diskon/grosir, pisahkan item bundle ke dalam 'Pool'
        foreach ($carts as $cart) {
            $priceToUse = $cart->product->price;

            // Cek Diskon normal
            if ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
                $priceToUse = $cart->product->discount_price;
            }

            // Jika Reseller dan kuantitas mencapai Grosir, lompati logika bundle sama sekali
            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $cartUpdates[$cart->id] = $cart->product->wholesale_price * $cart->quantity;
                continue;
            }

            // Cek validasi bundle
            $isBundleValid = $cart->product->is_bundle_active &&
                             (!$cart->product->bundle_end_date || Carbon::parse($cart->product->bundle_end_date)->isFuture()) &&
                             $cart->product->bundle_price > 0;

            if ($isBundleValid) {
                // Sebarkan ke pool untuk "dipasangkan" (1 per 1 kuantitas)
                for ($i = 0; $i < $cart->quantity; $i++) {
                    $bundlePool[] = [
                        'cart_id'      => $cart->id,
                        'normal_price' => $priceToUse,
                        'bundle_price' => $cart->product->bundle_price
                    ];
                }
            } else {
                // Item biasa langsung diset total gross_amount nya
                $cartUpdates[$cart->id] = $priceToUse * $cart->quantity;
            }
        }

        // 2. Jika ada item bundle, pasangkan per-2pcs
        if (!empty($bundlePool)) {
            // Kelompokkan pool berdasarkan nilai bundle_price-nya untuk menghindari error silang antar promo bundle
            $groupedBundles = collect($bundlePool)->groupBy('bundle_price');

            foreach ($groupedBundles as $bundlePrice => $items) {
                $totalItems = count($items);
                $pairs = floor($totalItems / 2); // Jumlah pasangan bundle
                $pricePerItemInBundle = $bundlePrice / 2; // Jika bundle 299.000, tiap kepingnya dinilai 149.500

                $itemIndex = 0;
                foreach ($items as $item) {
                    $cartId = $item['cart_id'];
                    
                    if (!isset($cartUpdates[$cartId])) {
                        $cartUpdates[$cartId] = 0;
                    }

                    // Jika item ini masuk kuota pasangan, beri harga paruhan bundle, jika jomblo beri harga aslinya
                    if ($itemIndex < $pairs * 2) {
                        $cartUpdates[$cartId] += $pricePerItemInBundle;
                    } else {
                        $cartUpdates[$cartId] += $item['normal_price'];
                    }
                    $itemIndex++;
                }
            }
        }

        // 3. Simpan massal ke database
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
