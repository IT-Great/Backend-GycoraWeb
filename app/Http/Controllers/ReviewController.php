<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class ReviewController extends Controller
{
    // Mengambil ulasan untuk halaman Product Detail
    // public function getProductReviews($productId)
    // {
    //     $reviews = Review::with('user:id,first_name,last_name,profile_image')
    //         ->where('product_id', $productId)
    //         ->latest()
    //         ->get();

    //     // Hitung rata-rata rating
    //     $average = $reviews->avg('rating');
    //     $total = $reviews->count();

    //     return response()->json([
    //         'reviews' => $reviews,
    //         'average_rating' => round($average, 1),
    //         'total_reviews' => $total
    //     ]);
    // }

    public function getProductReviews($slug)
    {
        // 1. Cari produk berdasarkan slug terlebih dahulu
        $product = Product::where('slug', $slug)->first();

        // 2. Jika produk tidak ditemukan, kembalikan error 404
        if (!$product) {
            return response()->json([
                'message' => 'Produk tidak ditemukan'
            ], 404);
        }

        // 3. Ambil review menggunakan ID dari produk yang ditemukan
        $reviews = Review::with('user:id,first_name,last_name,profile_image')
            ->where('product_id', $product->id)
            ->latest()
            ->get();

        // Hitung rata-rata rating
        $average = $reviews->avg('rating');
        $total = $reviews->count();

        return response()->json([
            'reviews' => $reviews,
            'average_rating' => round($average, 1),
            'total_reviews' => $total
        ]);
    }

    // User mengirimkan ulasan
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'transaction_id' => 'required',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // Maks 5MB
        ]);

        $user = auth()->user();

        // Cek apakah user sudah pernah mereview produk ini di transaksi ini
        $existingReview = Review::where('user_id', $user->id)
            ->where('product_id', $request->product_id)
            ->where('transaction_id', $request->transaction_id)
            ->first();

        if ($existingReview) {
            return response()->json(['message' => 'Anda sudah memberikan ulasan untuk produk ini.'], 400);
        }

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $path = Storage::disk('s3')->put('reviews', $file, 'public');
            $imageUrl = Storage::disk('s3')->url($path);
        }

        $review = Review::create([
            'user_id' => $user->id,
            'product_id' => $request->product_id,
            'transaction_id' => $request->transaction_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'image_url' => $imageUrl,
        ]);

        return response()->json(['message' => 'Ulasan berhasil dikirim!', 'data' => $review], 201);
    }

    public function indexAdmin()
    {
        // Tarik semua review beserta data user dan nama produknya
        $reviews = Review::with(['user:id,first_name,last_name,profile_image', 'product:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reviews);
    }

    public function destroyAdmin($id)
    {
        $review = Review::findOrFail($id);

        if ($review->image_url) {
            $path = parse_url($review->image_url, PHP_URL_PATH);
            $path = ltrim($path, '/');
            Storage::disk('s3')->delete($path);
        }

        $review->delete();

        return response()->json(['message' => 'Ulasan berhasil dihapus.']);
    }

    // =========================================================================
    // 👇 [BARU] FUNGSI AI REVIEW SUMMARIZER 👇
    // =========================================================================
    public function generateSummary()
    {
        try {
            // Ambil 100 review terakhir agar tidak melebihi limit token AI
            $reviews = Review::with('product:id,name')
                ->whereNotNull('comment')
                ->latest()
                ->limit(100)
                ->get();

            if ($reviews->isEmpty()) {
                return response()->json(['status' => 'success', 'data' => '<p>Belum ada ulasan yang cukup untuk dianalisis.</p>']);
            }

            $contextData = "Berikut adalah data ulasan pelanggan terbaru:\n\n";
            foreach ($reviews as $r) {
                $productName = $r->product ? $r->product->name : 'Produk Umum';
                $contextData .= "- Bintang {$r->rating}/5 untuk {$productName}: \"{$r->comment}\"\n";
            }

            $prompt = "Kamu adalah Head of Customer Experience. Baca data ulasan pelanggan e-commerce ini. Buatkan 3 hal:\n";
            $prompt .= "1. Sentimen Global (Berapa % positif, % negatif secara perkiraan).\n";
            $prompt .= "2. Poin Pujian Utama (Apa yang paling disukai pelanggan).\n";
            $prompt .= "3. Keluhan Utama & Saran Perbaikan (Apa masalah utamanya, misal: pengiriman, produk cacat).\n\n";
            $prompt .= "ATURAN MUTLAK:\n- Gunakan format tag HTML rapi (seperti <strong>, <ul>, <li>, <br>). Jangan gunakan markdown (* atau **).\n- Jangan sertakan tag <html> atau <body>.\n- Gunakan bahasa Indonesia yang profesional dan langsung pada intinya.\n\n";
            $prompt .= $contextData;

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

            $response = Http::timeout(30)->post($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.3] // Suhu rendah agar akurat/faktual
            ]);

            if ($response->successful()) {
                $text = $response->json('candidates.0.content.parts.0.text') ?? '';
                $text = preg_replace('/```html\n?|```/', '', $text);
                return response()->json(['status' => 'success', 'data' => trim($text)]);
            }

            return response()->json(['status' => 'error', 'message' => 'Gagal memproses API AI.'], 500);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AI Review Summary Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Layanan AI sedang sibuk.'], 500);
        }
    }

    // =========================================================================
    // 👇 [BARU] FUNGSI AI AUTO-REPLY GENERATOR 👇
    // =========================================================================
    public function generateAutoReply($id)
    {
        try {
            $review = Review::with(['user:id,first_name,last_name', 'product:id,name'])->findOrFail($id);
            $customerName = $review->user ? $review->user->first_name : 'Kak';
            $productName = $review->product ? $review->product->name : 'produk kami';

            $prompt = "Kamu adalah tim Customer Care yang empatik dan profesional. Buatkan draf balasan untuk pelanggan yang memberikan ulasan berikut:\n";
            $prompt .= "- Nama Pelanggan: {$customerName}\n";
            $prompt .= "- Produk: {$productName}\n";
            $prompt .= "- Rating: Bintang {$review->rating} dari 5\n";
            $prompt .= "- Komentar Pelanggan: \"{$review->comment}\"\n\n";

            if ($review->rating <= 3) {
                $prompt .= "Tugas: Buat balasan berisi permintaan maaf yang tulus, mengakui masalahnya, dan memberikan solusi (misal: menawarkan ganti rugi, bantuan via DM, atau retur). Nada bicara harus sangat sopan dan meredakan emosi.\n";
            } else {
                $prompt .= "Tugas: Buat balasan berisi ucapan terima kasih yang antusias, apresiasi dukungan mereka, dan ajakan untuk berbelanja lagi.\n";
            }
            $prompt .= "KEMBALIKAN HANYA TEKS BALASANNYA SAJA (tanpa tanda kutip, tanpa markdown, tanpa pengantar). Gunakan sapaan 'Halo {$customerName}' di awal.";

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

            $response = Http::timeout(30)->post($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.6]
            ]);

            if ($response->successful()) {
                $text = $response->json('candidates.0.content.parts.0.text') ?? '';
                return response()->json(['status' => 'success', 'reply' => trim($text)]);
            }

            return response()->json(['status' => 'error', 'message' => 'Gagal memproses balasan AI.'], 500);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AI Auto Reply Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Layanan AI sedang sibuk.'], 500);
        }
    }
}
