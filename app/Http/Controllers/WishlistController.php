<?php

// namespace App\Http\Controllers;

// use App\Models\Wishlist;
// use Illuminate\Http\Request;

// class WishlistController extends Controller
// {
//     // Mengambil daftar wishlist milik user yang sedang login
//     public function index(Request $request)
//     {
//         $wishlists = Wishlist::with('product')
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();

//         return response()->json($wishlists);
//     }

//     // Logika Toggle: Jika belum ada maka ditambahkan, jika sudah ada maka dihapus
//     public function toggle(Request $request)
//     {
//         $request->validate([
//             'product_id' => 'required|exists:products,id'
//         ]);

//         $userId = $request->user()->id;
//         $productId = $request->product_id;

//         $wishlist = Wishlist::where('user_id', $userId)
//             ->where('product_id', $productId)
//             ->first();

//         if ($wishlist) {
//             $wishlist->delete();
//             return response()->json(['status' => 'removed', 'message' => 'Removed from favorites']);
//         } else {
//             Wishlist::create([
//                 'user_id' => $userId,
//                 'product_id' => $productId
//             ]);
//             return response()->json(['status' => 'added', 'message' => 'Added to favorites']);
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $cacheKey = 'wishlists_user_' . $userId;

        // 👇 Cache wishlist per user selama 1 jam
        $wishlists = Cache::remember($cacheKey, 3600, function () use ($userId) {
            return Wishlist::with('product')
                ->where('user_id', $userId)
                ->latest()
                ->get();
        });

        return response()->json($wishlists);
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $userId = $request->user()->id;
        $productId = $request->product_id;

        $wishlist = Wishlist::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($wishlist) {
            $wishlist->delete();
            Cache::forget('wishlists_user_' . $userId); // 👇 Wajib: Hapus cache agar datanya ter-refresh
            return response()->json(['status' => 'removed', 'message' => 'Removed from favorites']);
        } else {
            Wishlist::create([
                'user_id' => $userId,
                'product_id' => $productId
            ]);
            Cache::forget('wishlists_user_' . $userId); // 👇 Wajib: Hapus cache agar datanya ter-refresh
            return response()->json(['status' => 'added', 'message' => 'Added to favorites']);
        }
    }
}
