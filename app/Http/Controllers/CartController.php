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
//     // 1. GET: Ambil Semua Isi Keranjang
//     // =========================================================================
//     public function index(Request $request)
//     {
//         // $request->user() menggantikan getUserIDFromToken()
//         $carts = $request->user()->carts()
//             ->with('product') // Mengambil relasi produk secara langsung (Eager Loading)
//             ->orderBy('id', 'desc')
//             ->get();

//         // Karena di frontend React Anda mengharapkan format JSON langsung berupa array object,
//         // kita bisa mengembalikan array langsung tanpa perlu membuat API Resource terpisah.
//         return response()->json($carts);
//     }

//     // =========================================================================
//     // 2. POST: Tambah ke Keranjang
//     // =========================================================================
//     // public function store(Request $request)
//     // {
//     //     $validated = $request->validate([
//     //         'product_id' => 'required|exists:products,id',
//     //         'quantity'   => 'required|integer|min:1',
//     //     ]);

//     //     $user = $request->user();

//     //     // Ambil data produk untuk validasi stok dan perhitungan harga
//     //     $product = Product::findOrFail($validated['product_id']);

//     //     // Cek apakah produk ini sudah ada di keranjang user
//     //     $existingCart = $user->carts()->where('product_id', $product->id)->first();

//     //     // Hitung kuantitas baru
//     //     $newQuantity = $validated['quantity'];
//     //     if ($existingCart) {
//     //         $newQuantity += $existingCart->quantity;
//     //     }

//     //     // Validasi Stok
//     //     if ($newQuantity > $product->stock) {
//     //         return response()->json([
//     //             'message' => 'Quantity exceeds available stock!'
//     //         ], 422); // 422 Unprocessable Entity
//     //     }

//     //     // Hitung Gross Amount dari database (BUKAN dari input frontend)
//     //     $grossAmount = $newQuantity * $product->price;

//     //     DB::transaction(function () use ($existingCart, $user, $product, $newQuantity, $grossAmount) {
//     //         if ($existingCart) {
//     //             // Update keranjang lama
//     //             $existingCart->update([
//     //                 'quantity'     => $newQuantity,
//     //                 'gross_amount' => $grossAmount,
//     //             ]);
//     //         } else {
//     //             // Insert keranjang baru
//     //             $user->carts()->create([
//     //                 'product_id'   => $product->id,
//     //                 'quantity'     => $newQuantity,
//     //                 'gross_amount' => $grossAmount,
//     //             ]);
//     //         }
//     //     });

//     //     return response()->json([
//     //         'message' => 'Added to cart successfully',
//     //         // Kita bisa mengambil ID keranjang terbaru
//     //         'cart_id' => $existingCart ? $existingCart->id : $user->carts()->latest('id')->first()->id,
//     //     ], 200); // Sesuai dengan golang yang mengirim w.WriteHeader(http.StatusOK)
//     // }

//     public function store(Request $request)
//     {
//         $validated = $request->validate([
//             'product_id' => 'required|exists:products,id',
//             'quantity'   => 'required|integer|min:1',
//             'color'      => 'nullable|string|max:50', // <-- Validasi warna
//         ]);

//         $user = $request->user();
//         $product = Product::findOrFail($validated['product_id']);

//         // Cek apakah produk DENGAN WARNA YANG SAMA sudah ada di keranjang
//         $existingCart = $user->carts()
//             ->where('product_id', $product->id)
//             ->where('color', $request->color) // <-- Pencarian spesifik varian
//             ->first();

//         $newQuantity = $validated['quantity'];
//         if ($existingCart) {
//             $newQuantity += $existingCart->quantity;
//         }

//         if ($newQuantity > $product->stock) {
//             return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
//         }

//         $grossAmount = $newQuantity * $product->price;

//         DB::transaction(function () use ($existingCart, $user, $product, $newQuantity, $grossAmount, $request) {
//             if ($existingCart) {
//                 $existingCart->update([
//                     'quantity'     => $newQuantity,
//                     'gross_amount' => $grossAmount,
//                 ]);
//             } else {
//                 $user->carts()->create([
//                     'product_id'   => $product->id,
//                     'color'        => $request->color, // <-- Simpan warna
//                     'quantity'     => $newQuantity,
//                     'gross_amount' => $grossAmount,
//                 ]);
//             }
//         });

//         return response()->json([
//             'message' => 'Added to cart successfully',
//             'cart_id' => $existingCart ? $existingCart->id : $user->carts()->latest('id')->first()->id,
//         ], 200);
//     }

//     // =========================================================================
//     // 3. PUT: Update Kuantitas
//     // =========================================================================
//     public function update(Request $request, $id)
//     {
//         $validated = $request->validate([
//             'quantity' => 'required|integer|min:1',
//         ]);

//         $user = $request->user();

//         // Pastikan keranjang ini ada dan milik user yang sedang login
//         $cart = $user->carts()->findOrFail($id);
//         $product = $cart->product;

//         // Validasi Stok
//         if ($validated['quantity'] > $product->stock) {
//             return response()->json([
//                 'message' => 'Stock limited!'
//             ], 422);
//         }

//         // Selalu hitung ulang harga berdasarkan DB
//         $grossAmount = $validated['quantity'] * $product->price;

//         $cart->update([
//             'quantity'     => $validated['quantity'],
//             'gross_amount' => $grossAmount,
//         ]);

//         return response()->json([
//             'message' => 'Cart updated successfully'
//         ]);
//     }

//     // =========================================================================
//     // 4. DELETE: Hapus dari Keranjang
//     // =========================================================================
//     public function destroy(Request $request, $id)
//     {
//         // Pastikan keranjang ini ada dan milik user yang sedang login
//         // Jika tidak ketemu atau bukan miliknya, otomatis throw 404 (ModelNotFoundException)
//         $cart = $request->user()->carts()->findOrFail($id);

//         $cart->delete();

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

class CartController extends Controller
{
    // =========================================================================
    // HELPER: SINKRONISASI HARGA DINAMIS BERDASARKAN MOQ
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

            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $priceToUse = $cart->product->wholesale_price;
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
