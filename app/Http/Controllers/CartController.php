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
use Carbon\Carbon; // 👇 Tambahkan import Carbon untuk pengecekan tanggal

class CartController extends Controller
{
    // =========================================================================
    // HELPER: SINKRONISASI HARGA DINAMIS BERDASARKAN MOQ & BUNDLE
    // =========================================================================
    private function syncCartPrices($user)
    {
        // 1. Ambil seluruh isi keranjang user beserta relasi produknya
        $carts = $user->carts()->with('product')->get();

        // 2. Hitung Total QTY yang ada di keranjang
        $totalCartQty = $carts->sum('quantity');
        $isReseller = $user->usertype === 'reseller';

        // 3. Update kembali seluruh gross_amount di database agar akurat dengan UI
        foreach ($carts as $cart) {
            $priceToUse = $cart->product->price;

            // 👇 Logika Pengecekan Bundle
            $isBundleValid = $cart->product->is_bundle_active &&
                             (!$cart->product->bundle_end_date || Carbon::parse($cart->product->bundle_end_date)->isFuture());

            // 👇 Hierarki Penentuan Harga: Grosir -> Bundle -> Diskon -> Normal
            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $priceToUse = $cart->product->wholesale_price;
            } elseif ($isBundleValid && $cart->product->bundle_price > 0) {
                $priceToUse = $cart->product->bundle_price;
            } elseif ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
                $priceToUse = $cart->product->discount_price;
            }

            $cart->update([
                'gross_amount' => $priceToUse * $cart->quantity
            ]);
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
                    'gross_amount' => 0, // Placeholder sementara
                ]);
            } else {
                $user->carts()->create([
                    'product_id'   => $product->id,
                    'color'        => $request->color,
                    'quantity'     => $newQuantity,
                    'gross_amount' => 0, // Placeholder sementara
                ]);
            }
        });

        // 👇 Panggil sinkronisasi setelah database disimpan
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
            'gross_amount' => 0, // Placeholder sementara
        ]);

        // 👇 Panggil sinkronisasi setelah database diupdate
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

        // 👇 Panggil sinkronisasi setelah item dihapus
        $this->syncCartPrices($user);

        return response()->json([
            'message' => 'Item removed'
        ]);
    }
}
