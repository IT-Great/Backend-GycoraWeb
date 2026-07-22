<?php

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
    // private function syncCartPrices($user)
    // {
    //     // 1. Ambil seluruh isi keranjang user beserta relasi produknya
    //     $carts = $user->carts()->with('product')->get();

    //     // 2. Hitung Total QTY yang ada di keranjang
    //     $totalCartQty = $carts->sum('quantity');
    //     $isReseller = $user->usertype === 'reseller';

    //     // 3. Update kembali seluruh gross_amount di database agar akurat dengan UI
    //     foreach ($carts as $cart) {
    //         $priceToUse = $cart->product->price;

    //         if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
    //             $priceToUse = $cart->product->wholesale_price;
    //         } elseif ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
    //             $priceToUse = $cart->product->discount_price;
    //         }

    //         $cart->update([
    //             'gross_amount' => $priceToUse * $cart->quantity
    //         ]);
    //     }
    // }

    // =========================================================================
    // HELPER: SINKRONISASI HARGA DINAMIS BERDASARKAN MOQ & BUNDLE
    // =========================================================================
    // private function syncCartPrices($user)
    // {
    //     // 1. Ambil seluruh isi keranjang user beserta relasi produknya
    //     $carts = $user->carts()->with('product')->get();

    //     // 2. Hitung Total QTY yang ada di keranjang
    //     $totalCartQty = $carts->sum('quantity');
    //     $isReseller = $user->usertype === 'reseller';

    //     // 3. Cek apakah promo Bundle masih berlaku (Sampai 20 Agustus 2026 Pukul 23:59 WIB)
    //     $isBundlePeriod = \Carbon\Carbon::now()->timezone('Asia/Jakarta')
    //                         ->lte(\Carbon\Carbon::parse('2026-08-20 23:59:59', 'Asia/Jakarta'));

    //     // 4. Cek syarat bundle: Apakah ada minimal 1 produk yang kodenya TIDAK berawalan EGB?
    //     $hasNonEgbProduct = false;
    //     foreach ($carts as $cart) {
    //         if (!str_starts_with($cart->product->sku, 'EGB')) {
    //             $hasNonEgbProduct = true;
    //             break;
    //         }
    //     }

    //     // 5. Update kembali seluruh gross_amount di database agar akurat dengan UI
    //     foreach ($carts as $cart) {
    //         $priceToUse = $cart->product->price;
    //         $sku = $cart->product->sku;

    //         // Prioritas Harga: Harga Grosir (Reseller) > Harga Bundle > Harga Diskon Standar
    //         if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
    //             $priceToUse = $cart->product->wholesale_price;
    //         } elseif ($isBundlePeriod && $hasNonEgbProduct && in_array($sku, ['EGB001', 'EGB002'])) {
    //             // Terapkan Bundle Price
    //             if ($sku === 'EGB001') {
    //                 $priceToUse = 299000;
    //             } elseif ($sku === 'EGB002') {
    //                 $priceToUse = 309000;
    //             }
    //         } elseif ($cart->product->discount_price > 0 && $cart->product->discount_price < $cart->product->price) {
    //             $priceToUse = $cart->product->discount_price;
    //         }

    //         $cart->update([
    //             'gross_amount' => $priceToUse * $cart->quantity
    //         ]);
    //     }
    // }

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

        // 3. Cek apakah promo Bundle masih berlaku (Sampai 20 Agustus 2026 Pukul 23:59 WIB)
        $isBundlePeriod = \Carbon\Carbon::now()->timezone('Asia/Jakarta')
                            ->lte(\Carbon\Carbon::parse('2026-08-20 23:59:59', 'Asia/Jakarta'));

        // 4. Cek syarat bundle: Apakah ada minimal 1 produk yang kodenya TIDAK berawalan EGB?
        $hasNonEgbProduct = false;
        foreach ($carts as $cart) {
            if (!str_starts_with($cart->product->sku, 'EGB')) {
                $hasNonEgbProduct = true;
                break;
            }
        }

        // 5. Update kembali seluruh gross_amount di database
        foreach ($carts as $cart) {
            $basePrice = $cart->product->price;
            $sku = $cart->product->sku;

            // Ambil harga diskon murni jika ada
            $discountPrice = ($cart->product->discount_price > 0 && $cart->product->discount_price < $basePrice)
                                ? $cart->product->discount_price
                                : $basePrice;

            $bundlePrice = null;

            // Gunakan str_starts_with agar varian seperti EGB001-BLK tetap terdeteksi
            if ($isBundlePeriod && $hasNonEgbProduct) {
                if (str_starts_with($sku, 'EGB001')) {
                    $bundlePrice = 299000;
                } elseif (str_starts_with($sku, 'EGB002')) {
                    $bundlePrice = 309000;
                }
            }

            // Penentuan Prioritas (Reseller > Bundle Termurah / Diskon)
            if ($isReseller && $cart->product->wholesale_price > 0 && $totalCartQty >= 24) {
                $priceToUse = $cart->product->wholesale_price;
            } else {
                if ($bundlePrice !== null) {
                    // Berikan harga paling murah antara Bundle vs Diskon Normal
                    $priceToUse = min($bundlePrice, $discountPrice);
                } else {
                    $priceToUse = $discountPrice;
                }
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
