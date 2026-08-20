<?php

// namespace App\Http\Controllers;

// use App\Models\Product;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Str;

// // Import class Intervention Image v3
// use Intervention\Image\ImageManager;
// use Intervention\Image\Drivers\Gd\Driver;

// class ProductController extends Controller
// {
//     // 1. MEMBACA DATA (BACA DARI REDIS CACHE, JIKA KOSONG BACA DARI MYSQL)
//     // =========================================================================
//     // public function index()
//     // {
//     //     // Menyimpan data di RAM (Redis) selama 1 Hari (86400 detik) dengan label 'catalog'
//     //     $products = Cache::tags(['catalog'])->remember('products.active', 86400, function () {
//     //         return Product::with('category')
//     //             // ->withSum(['transactionDetails' => function ($query) {
//     //             //     $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//     //             //           ->whereIn('transactions.status', ['completed']);
//     //             // }], 'quantity')
//     //             // ->where('status', 'active') // Gycora tidak ada field status di Model, tapi saya biarkan jika di DB ada
//     //             ->latest()
//     //             ->get();
//     //     });

//     //     // $products->map(function ($product) {
//     //     //     $product->total_sold = (int) $product->transaction_details_sum_quantity ?? 0;
//     //     //     unset($product->transaction_details_sum_quantity);
//     //     //     return $product;
//     //     // });

//     //     return response()->json($products, 200);
//     // }

//     public function index()
//     {
//         try {
//             // Gunakan metode pengambilan langsung (tanpa Cache/Redis) untuk debugging
//             $products = Product::with('category')
//                 ->latest()
//                 ->get();

//             return response()->json($products, 200);

//         } catch (\Exception $e) {
//             // Jika ada error dari database, kembalikan JSON, bukan halaman HTML
//             return response()->json([
//                 'status' => 'error',
//                 'message' => $e->getMessage()
//             ], 500);
//         }
//     }

//     public function inactiveProducts()
//     {
//         $products = Cache::tags(['catalog'])->remember('products.inactive', 86400, function () {
//             return Product::with('category')
//                 // ->where('status', 'inactive') // Gycora tidak ada field status di Model
//                 ->latest()
//                 ->get();
//         });

//         return response()->json($products, 200);
//     }

//     // public function show($id)
//     // {
//     //     // Cache per produk berdasarkan ID-nya
//     //     $product = Cache::tags(['catalog'])->remember("products.detail.{$id}", 86400, function () use ($id) {
//     //         return Product::with(['category', 'stocks' => function ($q) {
//     //             $q->orderBy('created_at', 'asc');
//     //         }])->findOrFail($id);
//     //     });

//     //     return response()->json($product, 200);
//     // }

//     public function show($id)
//     {
//         try {
//             // Pengambilan langsung tanpa Cache untuk mencegah error Redis Tags
//             $product = Product::with(['category', 'stocks' => function ($q) {
//                 $q->orderBy('created_at', 'asc');
//             }])->findOrFail($id);

//             return response()->json([
//                 'status' => 'success',
//                 'data' => $product
//             ], 200);

//         } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
//             // Tangani error jika ID tidak ditemukan dengan anggun
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'Produk tidak ditemukan.'
//             ], 404);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => $e->getMessage() // Ini akan menampilkan pesan error aslinya di response API
//             ], 500);
//         }
//     }

//     // public function store(Request $request)
//     // {
//     //     // 1. Validasi Field Gycora
//     //     $validator = Validator::make($request->all(), [
//     //         'category_id' => 'required|exists:categories,id',
//     //         'sku' => 'required|unique:products',
//     //         'name' => 'required|string|max:255',
//     //         // slug bisa di-generate otomatis di Model atau Controller
//     //         'description' => 'nullable|string',
//     //         'benefits' => 'nullable|string',
//     //         'price' => 'required|numeric|min:0',
//     //         'stock' => 'required|integer|min:0',
//     //         'image' => 'nullable|image', // Menggunakan input file 'image' dari frontend
//     //     ]);

//     //     if ($validator->fails()) {
//     //         return response()->json($validator->errors(), 422);
//     //     }

//     //     DB::beginTransaction();
//     //     try {
//     //         // Ambil semua data selain file gambar
//     //         $data = $request->except(['image']);

//     //         // Generate Slug Otomatis dari nama jika tidak dikirim dari frontend
//     //         if (empty($data['slug'])) {
//     //             $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//     //         }

//     //         // 1. Upload & Optimasi Gambar Utama
//     //         if ($request->hasFile('image')) {
//     //             // Helper function mengembalikan path relatif. Kita masukkan ke 'image_url' sesuai Model Gycora
//     //             $data['image_url'] = $this->optimizeAndSaveImage($request->file('image'), 'products');
//     //         }

//     //         // Simpan ke DB
//     //         $product = Product::create($data);

//     //         // Buat batch stok pertama kali
//     //         if ($request->stock > 0) {
//     //             $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//     //             ProductStock::create([
//     //                 'product_id' => $product->id,
//     //                 'batch_code' => $batchCode,
//     //                 'quantity' => $request->stock,
//     //                 'initial_quantity' => $request->stock,
//     //             ]);
//     //         }

//     //         DB::commit();

//     //         // Bersihkan Cache agar Katalog di Web User langsung ter-update!
//     //         Cache::tags(['catalog'])->flush();

//     //         return response()->json($product, 201);
//     //     } catch (\Exception $e) {
//     //         DB::rollBack();
//     //         return response()->json(['message' => $e->getMessage()], 500);
//     //     }
//     // }

//     // public function update(Request $request, $id)
//     // {
//     //     $product = Product::findOrFail($id);

//     //     $validator = Validator::make($request->all(), [
//     //         'category_id' => 'required|exists:categories,id',
//     //         'sku' => "required|unique:products,sku,$id", // SKU harus unique kecuali untuk produk ini sendiri
//     //         'name' => 'required|string|max:255',
//     //         'description' => 'nullable|string',
//     //         'benefits' => 'nullable|string',
//     //         'price' => 'required|numeric|min:0',
//     //         'image' => 'nullable|image',
//     //     ]);

//     //     if ($validator->fails()) {
//     //         return response()->json($validator->errors(), 422);
//     //     }

//     //     // Jangan izinkan update stok dari endpoint ini (Stok diupdate lewat ProductStockController)
//     //     $data = $request->except(['image', 'stock', '_method']);

//     //     // Jika nama diubah, ubah slug-nya juga
//     //     if ($request->has('name') && $request->name !== $product->name) {
//     //         $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//     //     }

//     //     // 1. Hapus & Ganti Gambar Utama
//     //     if ($request->hasFile('image')) {
//     //         if ($product->image_url) {
//     //             $oldPath = str_replace('/storage/', '', $product->image_url);
//     //             Storage::disk('public')->delete($oldPath);
//     //         }
//     //         // Gunakan fungsi helper untuk kompresi dan set ke field 'image_url'
//     //         $data['image_url'] = $this->optimizeAndSaveImage($request->file('image'), 'products');
//     //     }

//     //     $product->update($data);

//     //     Cache::tags(['catalog'])->flush();

//     //     return response()->json($product, 200);
//     // }

//     // public function destroy($id)
//     // {
//     //     // Gycora tidak memiliki field 'status' = 'inactive' berdasarkan Model yang Anda berikan.
//     //     // Jika Anda ingin soft delete, pastikan tabel `products` memiliki kolom `deleted_at` (SoftDeletes).
//     //     // Untuk sekarang, saya asumsikan ini melakukan hard delete atau Anda perlu menambahkan trait SoftDeletes di Model.

//     //     $product = Product::findOrFail($id);

//     //     // Hapus file gambar jika ada
//     //     if ($product->image_url) {
//     //         $oldPath = str_replace('/storage/', '', $product->image_url);
//     //         Storage::disk('public')->delete($oldPath);
//     //     }

//     //     $product->delete();

//     //     // Bersihkan Cache!
//     //     Cache::tags(['catalog'])->flush();

//     //     return response()->json(['message' => 'Product deleted'], 200);
//     // }

//     // public function restore($id)
//     // {
//     //     // Fungsi ini hanya bekerja jika Anda menggunakan SoftDeletes di model Product
//     //     $product = Product::withTrashed()->findOrFail($id);
//     //     $product->restore();

//     //     Cache::tags(['catalog'])->flush();

//     //     return response()->json(['message' => 'Product activated/restored'], 200);
//     // }

//     // public function forceDelete($id)
//     // {
//     //     // Gunakan withTrashed() jika menggunakan SoftDeletes
//     //     $product = Product::withTrashed()->findOrFail($id);

//     //     if ($product->image_url) {
//     //         $oldPath = str_replace('/storage/', '', $product->image_url);
//     //         Storage::disk('public')->delete($oldPath);
//     //     }

//     //     try {
//     //         $product->forceDelete(); // Menghapus permanen dari DB

//     //         Cache::tags(['catalog'])->flush();

//     //         return response()->json(['message' => 'Product deleted permanently'], 200);

//     //     } catch (\Illuminate\Database\QueryException $e) {
//     //         return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
//     //     }
//     // }

//     // =================================================================================
//     // [BARU] FUNGSI GENERATE PRE-SIGNED URL UNTUK DIRECT UPLOAD DARI REACT KE S3
//     // =================================================================================
//     // public function getPresignedUrl(Request $request)
//     // {
//     //     $request->validate([
//     //         'extension' => 'required|string',
//     //         'content_type' => 'required|string'
//     //     ]);

//     //     // Buat nama file unik
//     //     $filename = 'products/' . Str::random(40) . '.' . $request->extension;

//     //     // Buat URL sementara (berlaku 15 menit) untuk upload langsung dari browser ke S3
//     //     $uploadUrl = Storage::disk('s3')->temporaryUploadUrl(
//     //         $filename,
//     //         now()->addMinutes(15),
//     //         ['ContentType' => $request->content_type] // Wajib agar S3 menerima file dengan benar
//     //     );

//     //     // URL publik final untuk disimpan di database
//     //     $fileUrl = env('AWS_URL') . '/' . $filename;

//     //     return response()->json([
//     //         'upload_url' => $uploadUrl,
//     //         'file_url' => $fileUrl
//     //     ]);
//     // }

//     public function getPresignedUrl(Request $request)
//     {
//         $request->validate([
//             'extension' => 'required|string',
//             'content_type' => 'required|string'
//         ]);

//         $filename = 'products/' . Str::random(40) . '.' . $request->extension;

//         // temporaryUploadUrl mengembalikan ARRAY ['url' => '...', 'headers' => [...]]
//         $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
//             $filename,
//             now()->addMinutes(15),
//             ['ContentType' => $request->content_type]
//         );

//         $fileUrl = env('AWS_URL') . '/' . $filename;

//         return response()->json([
//             'upload_url' => $uploadResponse['url'],          // Ambil URL-nya saja (String)
//             'upload_headers' => $uploadResponse['headers'],  // Ambil Header wajib S3-nya
//             'file_url' => $fileUrl
//         ]);
//     }

//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => 'required|unique:products',
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'stock' => 'required|integer|min:0',
//             'image_url' => 'nullable|string', // [PERBAIKAN] Sekarang menerima URL dari Frontend
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         DB::beginTransaction();
//         try {
//             $data = $request->all();

//             if (empty($data['slug'])) {
//                 $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//             }

//             $product = Product::create($data);

//             if ($request->stock > 0) {
//                 $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->stock,
//                     'initial_quantity' => $request->stock,
//                 ]);
//             }

//             DB::commit();
//             Cache::tags(['catalog'])->flush();

//             return response()->json($product, 201);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             return response()->json(['message' => $e->getMessage()], 500);
//         }
//     }

//     public function update(Request $request, $id)
//     {
//         $product = Product::findOrFail($id);

//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => "required|unique:products,sku,$id",
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'image_url' => 'nullable|string', // [PERBAIKAN] Sekarang menerima URL
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         $data = $request->except(['stock', '_method']);

//         if ($request->has('name') && $request->name !== $product->name) {
//             $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//         }

//         // Hapus gambar lama dari S3 jika frontend mengirimkan gambar baru
//         if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->update($data);
//         Cache::tags(['catalog'])->flush();

//         return response()->json($product, 200);
//     }

//     public function destroy($id)
//     {
//         $product = Product::findOrFail($id);

//         // Hapus file gambar dari S3
//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->delete();
//         Cache::tags(['catalog'])->flush();

//         return response()->json(['message' => 'Product deleted'], 200);
//     }

//     // ... (Fungsi restore & forceDelete menggunakan logika S3 delete yang sama) ...
//     public function restore($id)
//     {
//         // Fungsi ini hanya bekerja jika Anda menggunakan SoftDeletes di model Product
//         $product = Product::withTrashed()->findOrFail($id);
//         $product->restore();

//         Cache::tags(['catalog'])->flush();

//         return response()->json(['message' => 'Product activated/restored'], 200);
//     }

//     public function forceDelete($id)
//     {
//         $product = Product::withTrashed()->findOrFail($id);

//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         try {
//             $product->forceDelete();
//             Cache::tags(['catalog'])->flush();
//             return response()->json(['message' => 'Product deleted permanently'], 200);
//         } catch (\Illuminate\Database\QueryException $e) {
//             return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
//         }
//     }

//     /**
//      * [BARU] FUNGSI HELPER UNTUK OPTIMASI GAMBAR
//      * Fungsi ini akan me-resize gambar dan mengonversinya ke WebP
//      */
//     private function optimizeAndSaveImage($file, $folder)
//     {
//         // 1. Inisialisasi Image Manager dengan driver GD
//         $manager = new ImageManager(new Driver());

//         // 2. Baca file yang diunggah
//         $image = $manager->read($file);

//         // 3. Scale Down: Maksimal lebar 1000px.
//         $image->scaleDown(width: 1000);

//         // 4. Encode ke format WebP dengan kualitas 80%
//         $encoded = $image->toWebp(80);

//         // 5. Buat nama file unik dengan ekstensi .webp
//         $filename = $folder . '/' . Str::random(40) . '.webp';

//         // 6. Simpan ke Local Storage (disk public)
//         Storage::disk('public')->put($filename, $encoded->toString());

//         // 7. Kembalikan path relatifnya
//         return '/storage/' . $filename;
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\Product;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Str;

// class ProductController extends Controller
// {
//     public function index()
//     {
//         try {
//             $products = Product::with('category')->latest()->get();
//             return response()->json($products, 200);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     public function inactiveProducts()
//     {
//         // [PERBAIKAN] Menghapus ->tags(['catalog'])
//         $products = Cache::remember('products.inactive', 86400, function () {
//             return Product::with('category')->latest()->get();
//         });
//         return response()->json($products, 200);
//     }

//     public function show($id)
//     {
//         try {
//             $product = Product::with(['category', 'stocks' => function ($q) {
//                 $q->orderBy('created_at', 'asc');
//             }])->findOrFail($id);

//             return response()->json(['status' => 'success', 'data' => $product], 200);
//         } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
//             return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     // =================================================================================
//     // FUNGSI GENERATE PRE-SIGNED URL UNTUK DIRECT UPLOAD DARI REACT KE S3
//     // =================================================================================
//     // public function getPresignedUrl(Request $request)
//     // {
//     //     $request->validate([
//     //         'extension' => 'required|string',
//     //         'content_type' => 'required|string'
//     //     ]);

//     //     $filename = 'products/' . Str::random(40) . '.' . $request->extension;

//     //     // temporaryUploadUrl mengembalikan ARRAY ['url' => '...', 'headers' => [...]]
//     //     $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
//     //         $filename,
//     //         now()->addMinutes(15),
//     //         ['ContentType' => $request->content_type]
//     //     );

//     //     $fileUrl = env('AWS_URL') . '/' . $filename;

//     //     return response()->json([
//     //         'upload_url' => $uploadResponse['url'],          // Ambil URL-nya saja
//     //         'upload_headers' => $uploadResponse['headers'],  // Ambil Header wajib S3-nya
//     //         'file_url' => $fileUrl
//     //     ]);
//     // }

//     public function getPresignedUrl(Request $request)
//     {
//         $request->validate([
//             'extension' => 'required|string',
//             'content_type' => 'required|string'
//         ]);

//         $filename = 'products/' . Str::random(40) . '.' . $request->extension;

//         $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
//             $filename,
//             now()->addMinutes(15),
//             [
//                 'ContentType' => $request->content_type,
//                 'ACL' => 'public-read' // <-- TAMBAHKAN BARIS INI KAWAN!
//             ]
//         );

//         $fileUrl = env('AWS_URL') . '/' . $filename;

//         return response()->json([
//             'upload_url' => $uploadResponse['url'],
//             'upload_headers' => $uploadResponse['headers'],
//             'file_url' => $fileUrl
//         ]);
//     }

//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => 'required|unique:products',
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'stock' => 'required|integer|min:0',
//             'image_url' => 'nullable|string',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         DB::beginTransaction();
//         try {
//             $data = $request->all();

//             if (empty($data['slug'])) {
//                 $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//             }

//             $product = Product::create($data);

//             if ($request->stock > 0) {
//                 $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->stock,
//                     'initial_quantity' => $request->stock,
//                 ]);
//             }

//             DB::commit();

//             // [PERBAIKAN] Gunakan Cache::forget biasa karena driver 'database' tidak mendukung tags
//             Cache::forget('products.active');
//             Cache::forget('products.inactive');

//             return response()->json($product, 201);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             return response()->json(['message' => $e->getMessage()], 500);
//         }
//     }

//     public function update(Request $request, $id)
//     {
//         $product = Product::findOrFail($id);

//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => "required|unique:products,sku,$id",
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'image_url' => 'nullable|string',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         $data = $request->except(['stock', '_method']);

//         if ($request->has('name') && $request->name !== $product->name) {
//             $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//         }

//         if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->update($data);

//         // [PERBAIKAN] Bersihkan Cache
//         Cache::forget('products.active');
//         Cache::forget('products.inactive');

//         return response()->json($product, 200);
//     }

//     public function destroy($id)
//     {
//         $product = Product::findOrFail($id);

//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->delete();

//         // [PERBAIKAN] Bersihkan Cache
//         Cache::forget('products.active');
//         Cache::forget('products.inactive');

//         return response()->json(['message' => 'Product deleted'], 200);
//     }

//     public function restore($id)
//     {
//         $product = Product::withTrashed()->findOrFail($id);
//         $product->restore();

//         // [PERBAIKAN] Bersihkan Cache
//         Cache::forget('products.active');
//         Cache::forget('products.inactive');

//         return response()->json(['message' => 'Product activated/restored'], 200);
//     }

//     public function forceDelete($id)
//     {
//         $product = Product::withTrashed()->findOrFail($id);

//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         try {
//             $product->forceDelete();

//             // [PERBAIKAN] Bersihkan Cache
//             Cache::forget('products.active');
//             Cache::forget('products.inactive');

//             return response()->json(['message' => 'Product deleted permanently'], 200);
//         } catch (\Illuminate\Database\QueryException $e) {
//             return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\Product;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Str;

// class ProductController extends Controller
// {
//     public function index()
//     {
//         try {
//             // HANYA AMBIL YANG ACTIVE
//             $products = Product::with('category')->where('status', 'active')->latest()->get();
//             return response()->json($products, 200);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     public function inactiveProducts()
//     {
//         // HANYA AMBIL YANG INACTIVE
//         $products = Product::with('category')->where('status', 'inactive')->latest()->get();
//         return response()->json($products, 200);
//     }

//     public function show($id)
//     {
//         try {
//             $product = Product::with(['category', 'stocks' => function ($q) {
//                 $q->orderBy('created_at', 'asc');
//             }])->findOrFail($id);

//             return response()->json(['status' => 'success', 'data' => $product], 200);
//         } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
//             return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     public function getPresignedUrl(Request $request)
//     {
//         $request->validate([
//             'extension' => 'required|string',
//             'content_type' => 'required|string'
//         ]);

//         $filename = 'products/' . Str::random(40) . '.' . $request->extension;

//         $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
//             $filename,
//             now()->addMinutes(15),
//             [
//                 'ContentType' => $request->content_type,
//                 'ACL' => 'public-read'
//             ]
//         );

//         $fileUrl = env('AWS_URL') . '/' . $filename;

//         return response()->json([
//             'upload_url' => $uploadResponse['url'],
//             'upload_headers' => $uploadResponse['headers'],
//             'file_url' => $fileUrl
//         ]);
//     }

//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => 'required|unique:products',
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'stock' => 'required|integer|min:0',
//             'image_url' => 'nullable|string',
//             'variant_video' => 'nullable|string',
//             'color' => 'nullable|array',
//             'status' => 'required|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         DB::beginTransaction();
//         try {
//             $data = $request->all();

//             if (empty($data['slug'])) {
//                 $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//             }

//             $product = Product::create($data);

//             if ($request->stock > 0) {
//                 $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->stock,
//                     'initial_quantity' => $request->stock,
//                 ]);
//             }

//             DB::commit();
//             return response()->json($product, 201);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             return response()->json(['message' => $e->getMessage()], 500);
//         }
//     }

//     public function update(Request $request, $id)
//     {
//         $product = Product::findOrFail($id);

//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => "required|unique:products,sku,$id",
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'image_url' => 'nullable|string',
//             'variant_video' => 'nullable|string',
//             'color' => 'nullable|array',
//             'status' => 'required|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         $data = $request->except(['stock', '_method']);

//         if ($request->has('name') && $request->name !== $product->name) {
//             $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//         }

//         if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->update($data);
//         return response()->json($product, 200);
//     }

//     public function destroy($id)
//     {
//         $product = Product::findOrFail($id);

//         // UBAH STATUS JADI INACTIVE (NONAKTIFKAN)
//         $product->update(['status' => 'inactive']);

//         return response()->json(['message' => 'Product deactivated'], 200);
//     }

//     public function restore($id)
//     {
//         $product = Product::findOrFail($id);

//         // KEMBALIKAN KE ACTIVE
//         $product->update(['status' => 'active']);

//         return response()->json(['message' => 'Product activated'], 200);
//     }

//     public function forceDelete($id)
//     {
//         $product = Product::findOrFail($id);

//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         try {
//             $product->delete(); // Hapus permanen
//             return response()->json(['message' => 'Product deleted permanently'], 200);
//         } catch (\Illuminate\Database\QueryException $e) {
//             return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\Product;
// use App\Models\ProductStock;
// use Illuminate\Database\Eloquent\ModelNotFoundException;
// use Illuminate\Database\QueryException;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Str;

// class ProductController extends Controller
// {
//     public function index()
//     {
//         try {
//             // 👇 Simpan query ke cache Redis selama 1 jam (3600 detik).
//             // Jika ada cache bernama 'products_active', langsung gunakan itu.
//             $products = Cache::remember('products_active', 3600, function () {
//                 return Product::with('category')->where('status', 'active')->latest()->get();
//             });

//             return response()->json($products, 200);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     public function inactiveProducts()
//     {
//         // HANYA AMBIL YANG INACTIVE
//         $products = Product::with('category')->where('status', 'inactive')->latest()->get();

//         return response()->json($products, 200);
//     }

//     public function show($slug)
//     {
//         try {
//             $product = Product::with(['category', 'stocks' => function ($q) {
//                 $q->orderBy('created_at', 'asc');
//             }])->where('slug', $slug)->firstOrFail();

//             return response()->json(['status' => 'success', 'data' => $product], 200);
//         } catch (ModelNotFoundException $e) {
//             return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     public function getPresignedUrl(Request $request)
//     {
//         $request->validate([
//             'extension' => 'required|string',
//             'content_type' => 'required|string',
//         ]);

//         $filename = 'products/'.Str::random(40).'.'.$request->extension;

//         $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
//             $filename,
//             now()->addMinutes(15),
//             [
//                 'ContentType' => $request->content_type,
//                 'ACL' => 'public-read',
//             ]
//         );

//         $fileUrl = env('AWS_URL').'/'.$filename;

//         return response()->json([
//             'upload_url' => $uploadResponse['url'],
//             'upload_headers' => $uploadResponse['headers'],
//             'file_url' => $fileUrl,
//         ]);
//     }

//     // public function store(Request $request)
//     // {
//     //     $validator = Validator::make($request->all(), [
//     //         'category_id' => 'required|exists:categories,id',
//     //         'sku' => 'required|unique:products',
//     //         'name' => 'required|string|max:255',
//     //         'description' => 'nullable|string',
//     //         'benefits' => 'nullable|string',
//     //         'price' => 'required|numeric|min:0',
//     //         'discount_price' => 'nullable|numeric|min:0', // Tambahkan validasi
//     //         'voucher_discount_price' => 'nullable|numeric|min:0', // <--- BARU
//     //         'stock' => 'required|integer|min:0',
//     //         'image_url' => 'nullable|string',
//     //         'variant_video' => 'nullable|string',
//     //         'color' => 'nullable|array',
//     //         'status' => 'required|in:active,inactive',
//     //     ]);

//     //     if ($validator->fails()) {
//     //         return response()->json($validator->errors(), 422);
//     //     }

//     //     DB::beginTransaction();
//     //     try {
//     //         $data = $request->all();

//     //         if (empty($data['slug'])) {
//     //             // $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//     //             $data['slug'] = Str::slug($data['name']);
//     //         }

//     //         $product = Product::create($data);

//     //         if ($request->stock > 0) {
//     //             $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//     //             ProductStock::create([
//     //                 'product_id' => $product->id,
//     //                 'batch_code' => $batchCode,
//     //                 'quantity' => $request->stock,
//     //                 'initial_quantity' => $request->stock,
//     //             ]);
//     //         }

//     //         DB::commit();
//     //         return response()->json($product, 201);
//     //     } catch (\Exception $e) {
//     //         DB::rollBack();
//     //         return response()->json(['message' => $e->getMessage()], 500);
//     //     }
//     // }

//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => 'required|unique:products',
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'discount_price' => 'nullable|numeric|min:0',
//             'wholesale_price' => 'nullable|numeric|min:0',
//             'voucher_discount_price' => 'nullable|numeric|min:0',

//             // 👇 Validasi Bundle 👇
//             'is_bundle_active' => 'boolean',
//             'bundle_price' => 'nullable|numeric|min:0',
//             'bundle_start_date' => 'nullable|date',
//             'bundle_end_date' => 'nullable|date',

//             // 👇 Validasi Multi Currency 👇
//             'prices' => 'nullable|array',
//             'prices.*' => 'nullable|numeric|min:0',
//             'discount_prices' => 'nullable|array',
//             'discount_prices.*' => 'nullable|numeric|min:0',
//             'wholesale_prices' => 'nullable|array',
//             'wholesale_prices.*' => 'nullable|numeric|min:0',
//             'voucher_discount_prices' => 'nullable|array',
//             'voucher_discount_prices.*' => 'nullable|numeric|min:0',
//             'bundle_prices' => 'nullable|array', // <-- BARU
//             'bundle_prices.*' => 'nullable|numeric|min:0', // <-- BARU

//             'stock' => 'required|integer|min:0',
//             'image_url' => 'nullable|string',
//             'variant_video' => 'nullable|string',
//             'color' => 'nullable|array',
//             'status' => 'required|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         DB::beginTransaction();
//         try {
//             $data = $request->all();

//             if (empty($data['slug'])) {
//                 $data['slug'] = Str::slug($data['name']);
//             }

//             // Memastikan data Boolean dikonversi dengan benar
//             $data['is_bundle_active'] = filter_var($request->is_bundle_active, FILTER_VALIDATE_BOOLEAN);

//             // Tangani Array JSON jika kosong
//             $data['prices'] = $request->input('prices', null);
//             $data['discount_prices'] = $request->input('discount_prices', null);
//             $data['wholesale_prices'] = $request->input('wholesale_prices', null); // 👇 Tambahkan ini
//             $data['voucher_discount_prices'] = $request->input('voucher_discount_prices', null); // 👇 Tambahkan ini

//             $data['bundle_prices'] = $request->input('bundle_prices', null); // <-- BARU
//             $data['bundle_start_date'] = $request->input('bundle_start_date', null); // <-- BARU
//             $data['bundle_end_date'] = $request->input('bundle_end_date', null); // <-- BARU

//             $product = Product::create($data);

//             if ($request->stock > 0) {
//                 $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->stock,
//                     'initial_quantity' => $request->stock,
//                 ]);
//             }

//             DB::commit();

//             return response()->json($product, 201);
//         } catch (\Exception $e) {
//             DB::rollBack();

//             return response()->json(['message' => $e->getMessage()], 500);
//         }
//     }

//     // public function update(Request $request, $id)
//     // {
//     //     $product = Product::findOrFail($id);

//     //     $validator = Validator::make($request->all(), [
//     //         'category_id' => 'required|exists:categories,id',
//     //         'sku' => "required|unique:products,sku,$id",
//     //         'name' => 'required|string|max:255',
//     //         'description' => 'nullable|string',
//     //         'benefits' => 'nullable|string',
//     //         'price' => 'required|numeric|min:0',
//     //         'discount_price' => 'nullable|numeric|min:0', // Tambahkan validasi
//     //         'voucher_discount_price' => 'nullable|numeric|min:0', // <--- BARU
//     //         'image_url' => 'nullable|string',
//     //         'variant_video' => 'nullable|string',
//     //         'color' => 'nullable|array',
//     //         'status' => 'required|in:active,inactive',
//     //     ]);

//     //     if ($validator->fails()) {
//     //         return response()->json($validator->errors(), 422);
//     //     }

//     //     $data = $request->except(['stock', '_method']);

//     //     if ($request->has('name') && $request->name !== $product->name) {
//     //         // $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
//     //         $data['slug'] = Str::slug($request->name);
//     //     }

//     //     if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//     //         $oldKey = str_replace(env('AWS_URL') . '/', '', $product->image_url);
//     //         Storage::disk('s3')->delete($oldKey);
//     //     }

//     //     $product->update($data);
//     //     return response()->json($product, 200);
//     // }

//     // public function update(Request $request, $id)
//     // {
//     //     $product = Product::findOrFail($id);

//     //     $validator = Validator::make($request->all(), [
//     //         'category_id' => 'required|exists:categories,id',
//     //         'sku' => "required|unique:products,sku,$id",
//     //         'name' => 'required|string|max:255',
//     //         'description' => 'nullable|string',
//     //         'benefits' => 'nullable|string',
//     //         'price' => 'required|numeric|min:0',
//     //         'discount_price' => 'nullable|numeric|min:0',
//     //         'wholesale_price' => 'nullable|numeric|min:0',
//     //         'voucher_discount_price' => 'nullable|numeric|min:0',

//     //         // 👇 Validasi Multi Currency 👇
//     //         'prices' => 'nullable|array',
//     //         'prices.*' => 'nullable|numeric|min:0',
//     //         'discount_prices' => 'nullable|array',
//     //         'discount_prices.*' => 'nullable|numeric|min:0',

//     //         'wholesale_prices' => 'nullable|array',
//     //         'wholesale_prices.*' => 'nullable|numeric|min:0',
//     //         'voucher_discount_prices' => 'nullable|array',
//     //         'voucher_discount_prices.*' => 'nullable|numeric|min:0',

//     //         'image_url' => 'nullable|string',
//     //         'variant_video' => 'nullable|string',
//     //         'color' => 'nullable|array',
//     //         'status' => 'required|in:active,inactive',
//     //     ]);

//     //     if ($validator->fails()) {
//     //         return response()->json($validator->errors(), 422);
//     //     }

//     //     $data = $request->except(['stock', '_method']);

//     //     if ($request->has('name') && $request->name !== $product->name) {
//     //         $data['slug'] = Str::slug($request->name);
//     //     }

//     //     // Tangani Array JSON jika kosong
//     //     $data['prices'] = $request->input('prices', null);
//     //     $data['discount_prices'] = $request->input('discount_prices', null);
//     //     $data['wholesale_prices'] = $request->input('wholesale_prices', null);
//     //     $data['voucher_discount_prices'] = $request->input('voucher_discount_prices', null);

//     //     if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//     //         $oldKey = str_replace(env('AWS_URL').'/', '', $product->image_url);
//     //         Storage::disk('s3')->delete($oldKey);
//     //     }

//     //     $product->update($data);

//     //     return response()->json($product, 200);
//     // }

//     public function update(Request $request, $id)
//     {
//         $product = Product::findOrFail($id);

//         $validator = Validator::make($request->all(), [
//             'category_id' => 'required|exists:categories,id',
//             'sku' => "required|unique:products,sku,$id",
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//             'benefits' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'discount_price' => 'nullable|numeric|min:0',
//             'wholesale_price' => 'nullable|numeric|min:0',
//             'voucher_discount_price' => 'nullable|numeric|min:0',

//             // 👇 Validasi Bundle 👇
//             'is_bundle_active' => 'boolean',
//             'bundle_price' => 'nullable|numeric|min:0',
//             'bundle_start_date' => 'nullable|date',
//             'bundle_end_date' => 'nullable|date',

//             // Validasi Multi Currency
//             'prices' => 'nullable|array',
//             'prices.*' => 'nullable|numeric|min:0',
//             'discount_prices' => 'nullable|array',
//             'discount_prices.*' => 'nullable|numeric|min:0',
//             'wholesale_prices' => 'nullable|array',
//             'wholesale_prices.*' => 'nullable|numeric|min:0',
//             'voucher_discount_prices' => 'nullable|array',
//             'voucher_discount_prices.*' => 'nullable|numeric|min:0',
//             'bundle_prices' => 'nullable|array', // <-- BARU
//             'bundle_prices.*' => 'nullable|numeric|min:0', // <-- BARU

//             'image_url' => 'nullable|string',
//             'variant_video' => 'nullable|string',
//             'color' => 'nullable|array',
//             'status' => 'required|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         $data = $request->except(['stock', '_method']);

//         if ($request->has('name') && $request->name !== $product->name) {
//             $data['slug'] = Str::slug($request->name);
//         }

//         $data['is_bundle_active'] = filter_var($request->is_bundle_active, FILTER_VALIDATE_BOOLEAN);

//         // Tangani Array JSON jika kosong
//         $data['prices'] = $request->input('prices', null);
//         $data['discount_prices'] = $request->input('discount_prices', null);
//         $data['wholesale_prices'] = $request->input('wholesale_prices', null);
//         $data['voucher_discount_prices'] = $request->input('voucher_discount_prices', null);
//         $data['bundle_prices'] = $request->input('bundle_prices', null); // <-- BARU
//         $data['bundle_start_date'] = $request->input('bundle_start_date', null); // <-- BARU
//         $data['bundle_end_date'] = $request->input('bundle_end_date', null); // <-- BARU

//         if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
//             $oldKey = str_replace(env('AWS_URL').'/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         $product->update($data);

//         return response()->json($product, 200);
//     }

//     public function destroy($id)
//     {
//         $product = Product::findOrFail($id);

//         // UBAH STATUS JADI INACTIVE (NONAKTIFKAN)
//         $product->update(['status' => 'inactive']);

//         return response()->json(['message' => 'Product deactivated'], 200);
//     }

//     public function restore($id)
//     {
//         $product = Product::findOrFail($id);

//         // KEMBALIKAN KE ACTIVE
//         $product->update(['status' => 'active']);

//         return response()->json(['message' => 'Product activated'], 200);
//     }

//     public function forceDelete($id)
//     {
//         $product = Product::findOrFail($id);

//         if ($product->image_url) {
//             $oldKey = str_replace(env('AWS_URL').'/', '', $product->image_url);
//             Storage::disk('s3')->delete($oldKey);
//         }

//         try {
//             $product->delete(); // Hapus permanen

//             return response()->json(['message' => 'Product deleted permanently'], 200);
//         } catch (QueryException $e) {
//             return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
//         }
//     }

//     // FUNGSI BARU UNTUK MENDAPATKAN VARIAN (PRODUK SAUDARA)
//     public function getRelatedVariants($slug)
//     {
//         try {
//             $product = Product::where('slug', $slug)->firstOrFail();

//             // 1. Ekstrak nama warna dari atribut JSON 'color'
//             $colorName = '';
//             if (is_array($product->color) && count($product->color) > 0) {
//                 $firstColor = $product->color[0];
//                 if (isset($firstColor['name']) && ! empty($firstColor['name'])) {
//                     $colorName = trim($firstColor['name']);
//                 }
//             }

//             $baseName = $product->name; // Set default awal

//             // 2. Logika Dinamis: Hapus "Nama Warna" dari "Nama Produk Lengkap"
//             if (! empty($colorName)) {
//                 // Kita gunakan Regex untuk mencari dan menghapus nama warna yang berada di PALING AKHIR teks (case-insensitive)
//                 // Contoh: "Gycora Shampoo Rose Gold" dikurangi "Rose Gold" -> "Gycora Shampoo"
//                 $pattern = '/'.preg_quote($colorName, '/').'$/i';
//                 $baseName = preg_replace($pattern, '', $product->name);
//                 $baseName = trim($baseName);

//                 // Jaga-jaga jika hasilnya kosong
//                 if (empty($baseName)) {
//                     $baseName = $product->name;
//                 }
//             } else {
//                 // Fallback: Jika kebetulan produk lama tidak punya nama warna, kita buang 1 kata paling akhir
//                 $nameParts = explode(' ', $product->name);
//                 if (count($nameParts) > 1) {
//                     array_pop($nameParts);
//                     $baseName = implode(' ', $nameParts);
//                 }
//             }

//             // 3. Cari produk sekeluarga berdasarkan Base Name murni yang sudah didapat
//             $relatedProducts = Product::where('status', 'active')
//                 ->where('category_id', $product->category_id)
//                 ->where('name', 'like', $baseName.'%')
//                 ->get(['id', 'name', 'slug', 'color', 'image_url']);

//             return response()->json([
//                 'status' => 'success',
//                 'current_product' => $product,
//                 'base_name_detected' => $baseName, // (Opsional) Mengirim base name untuk kemudahan debugging
//                 'variants' => $relatedProducts,
//             ], 200);

//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }

//     // FUNGSI BARU: MENGAMBIL PRODUK DENGAN STOK KRITIS (<= 5)
//     public function getLowStockProducts()
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->where('stock', '<=', 5)
//                 ->orderBy('stock', 'asc')
//                 ->get(['id', 'name', 'sku', 'stock', 'image_url']);

//             return response()->json([
//                 'status' => 'success',
//                 'data' => $products,
//             ], 200);
//         } catch (\Exception $e) {
//             return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index()
    {
        try {
            // 👇 Simpan query ke cache Redis selama 1 jam (3600 detik).
            // Jika ada cache bernama 'products_active', langsung gunakan itu.
            $products = Cache::remember('products_active', 3600, function () {
                return Product::with('category')->where('status', 'active')->latest()->get();
            });

            return response()->json($products, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function inactiveProducts()
    {
        // HANYA AMBIL YANG INACTIVE
        $products = Product::with('category')->where('status', 'inactive')->latest()->get();

        return response()->json($products, 200);
    }

    public function show($slug)
    {
        try {
            $product = Product::with(['category', 'stocks' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])->where('slug', $slug)->firstOrFail();

            return response()->json(['status' => 'success', 'data' => $product], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getPresignedUrl(Request $request)
    {
        $request->validate([
            'extension' => 'required|string',
            'content_type' => 'required|string',
        ]);

        $filename = 'products/'.Str::random(40).'.'.$request->extension;

        $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
            $filename,
            now()->addMinutes(15),
            [
                'ContentType' => $request->content_type,
                'ACL' => 'public-read',
            ]
        );

        $fileUrl = env('AWS_URL').'/'.$filename;

        return response()->json([
            'upload_url' => $uploadResponse['url'],
            'upload_headers' => $uploadResponse['headers'],
            'file_url' => $fileUrl,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'sku' => 'required|unique:products',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'benefits' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'voucher_discount_price' => 'nullable|numeric|min:0',

            // 👇 Validasi Bundle 👇
            'is_bundle_active' => 'boolean',
            'bundle_price' => 'nullable|numeric|min:0',
            'bundle_start_date' => 'nullable|date',
            'bundle_end_date' => 'nullable|date',

            // 👇 Validasi Multi Currency 👇
            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
            'discount_prices' => 'nullable|array',
            'discount_prices.*' => 'nullable|numeric|min:0',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*' => 'nullable|numeric|min:0',
            'voucher_discount_prices' => 'nullable|array',
            'voucher_discount_prices.*' => 'nullable|numeric|min:0',
            'bundle_prices' => 'nullable|array',
            'bundle_prices.*' => 'nullable|numeric|min:0',

            'stock' => 'required|integer|min:0',
            'image_url' => 'nullable|string',
            'variant_video' => 'nullable|string',
            'color' => 'nullable|array',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $data = $request->all();

            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            // Memastikan data Boolean dikonversi dengan benar
            $data['is_bundle_active'] = filter_var($request->is_bundle_active, FILTER_VALIDATE_BOOLEAN);

            // Tangani Array JSON jika kosong
            $data['prices'] = $request->input('prices', null);
            $data['discount_prices'] = $request->input('discount_prices', null);
            $data['wholesale_prices'] = $request->input('wholesale_prices', null);
            $data['voucher_discount_prices'] = $request->input('voucher_discount_prices', null);

            $data['bundle_prices'] = $request->input('bundle_prices', null);
            $data['bundle_start_date'] = $request->input('bundle_start_date', null);
            $data['bundle_end_date'] = $request->input('bundle_end_date', null);

            $product = Product::create($data);

            if ($request->stock > 0) {
                $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->stock,
                    'initial_quantity' => $request->stock,
                ]);
            }

            DB::commit();

            // 👇 Hapus cache agar data terbaru segera ditarik oleh user 👇
            Cache::forget('products_active');

            return response()->json($product, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'sku' => "required|unique:products,sku,$id",
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'benefits' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'voucher_discount_price' => 'nullable|numeric|min:0',

            // 👇 Validasi Bundle 👇
            'is_bundle_active' => 'boolean',
            'bundle_price' => 'nullable|numeric|min:0',
            'bundle_start_date' => 'nullable|date',
            'bundle_end_date' => 'nullable|date',

            // Validasi Multi Currency
            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
            'discount_prices' => 'nullable|array',
            'discount_prices.*' => 'nullable|numeric|min:0',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*' => 'nullable|numeric|min:0',
            'voucher_discount_prices' => 'nullable|array',
            'voucher_discount_prices.*' => 'nullable|numeric|min:0',
            'bundle_prices' => 'nullable|array',
            'bundle_prices.*' => 'nullable|numeric|min:0',

            'image_url' => 'nullable|string',
            'variant_video' => 'nullable|string',
            'color' => 'nullable|array',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->except(['stock', '_method']);

        if ($request->has('name') && $request->name !== $product->name) {
            $data['slug'] = Str::slug($request->name);
        }

        $data['is_bundle_active'] = filter_var($request->is_bundle_active, FILTER_VALIDATE_BOOLEAN);

        // Tangani Array JSON jika kosong
        $data['prices'] = $request->input('prices', null);
        $data['discount_prices'] = $request->input('discount_prices', null);
        $data['wholesale_prices'] = $request->input('wholesale_prices', null);
        $data['voucher_discount_prices'] = $request->input('voucher_discount_prices', null);
        $data['bundle_prices'] = $request->input('bundle_prices', null);
        $data['bundle_start_date'] = $request->input('bundle_start_date', null);
        $data['bundle_end_date'] = $request->input('bundle_end_date', null);

        if ($request->has('image_url') && $request->image_url !== $product->image_url && $product->image_url) {
            $oldKey = str_replace(env('AWS_URL').'/', '', $product->image_url);
            Storage::disk('s3')->delete($oldKey);
        }

        $product->update($data);

        // 👇 Hapus cache agar data terbaru segera ditarik oleh user 👇
        Cache::forget('products_active');

        return response()->json($product, 200);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        // UBAH STATUS JADI INACTIVE (NONAKTIFKAN)
        $product->update(['status' => 'inactive']);

        // 👇 Hapus cache agar data yang dinonaktifkan hilang dari frontend 👇
        Cache::forget('products_active');

        return response()->json(['message' => 'Product deactivated'], 200);
    }

    public function restore($id)
    {
        $product = Product::findOrFail($id);

        // KEMBALIKAN KE ACTIVE
        $product->update(['status' => 'active']);

        // 👇 Hapus cache agar data yang diaktifkan kembali muncul di frontend 👇
        Cache::forget('products_active');

        return response()->json(['message' => 'Product activated'], 200);
    }

    public function forceDelete($id)
    {
        $product = Product::findOrFail($id);

        if ($product->image_url) {
            $oldKey = str_replace(env('AWS_URL').'/', '', $product->image_url);
            Storage::disk('s3')->delete($oldKey);
        }

        try {
            $product->delete(); // Hapus permanen

            // 👇 Hapus cache jika data dihapus permanen 👇
            Cache::forget('products_active');

            return response()->json(['message' => 'Product deleted permanently'], 200);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
        }
    }

    // FUNGSI BARU UNTUK MENDAPATKAN VARIAN (PRODUK SAUDARA)
    public function getRelatedVariants($slug)
    {
        try {
            $product = Product::where('slug', $slug)->firstOrFail();

            // 1. Ekstrak nama warna dari atribut JSON 'color'
            $colorName = '';
            if (is_array($product->color) && count($product->color) > 0) {
                $firstColor = $product->color[0];
                if (isset($firstColor['name']) && ! empty($firstColor['name'])) {
                    $colorName = trim($firstColor['name']);
                }
            }

            $baseName = $product->name; // Set default awal

            // 2. Logika Dinamis: Hapus "Nama Warna" dari "Nama Produk Lengkap"
            if (! empty($colorName)) {
                // Kita gunakan Regex untuk mencari dan menghapus nama warna yang berada di PALING AKHIR teks (case-insensitive)
                // Contoh: "Gycora Shampoo Rose Gold" dikurangi "Rose Gold" -> "Gycora Shampoo"
                $pattern = '/'.preg_quote($colorName, '/').'$/i';
                $baseName = preg_replace($pattern, '', $product->name);
                $baseName = trim($baseName);

                // Jaga-jaga jika hasilnya kosong
                if (empty($baseName)) {
                    $baseName = $product->name;
                }
            } else {
                // Fallback: Jika kebetulan produk lama tidak punya nama warna, kita buang 1 kata paling akhir
                $nameParts = explode(' ', $product->name);
                if (count($nameParts) > 1) {
                    array_pop($nameParts);
                    $baseName = implode(' ', $nameParts);
                }
            }

            // 3. Cari produk sekeluarga berdasarkan Base Name murni yang sudah didapat
            $relatedProducts = Product::where('status', 'active')
                ->where('category_id', $product->category_id)
                ->where('name', 'like', $baseName.'%')
                ->get(['id', 'name', 'slug', 'color', 'image_url']);

            return response()->json([
                'status' => 'success',
                'current_product' => $product,
                'base_name_detected' => $baseName, // (Opsional) Mengirim base name untuk kemudahan debugging
                'variants' => $relatedProducts,
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =====================================================================
    // 👇 ALGORITMA COLLABORATIVE FILTERING (FREQUENTLY BOUGHT TOGETHER) 👇
    // =====================================================================
    public function getRecommendations($id)
    {
        try {
            // 1. Cari SEMUA ID Transaksi yang pernah memuat produk ini
            $transactionIds = DB::table('transaction_details')
                ->where('product_id', $id)
                ->pluck('transaction_id');

            // 2. Collaborative Filtering: Dari transaksi-transaksi di atas,
            // produk apa lagi yang paling banyak dibeli bersamaan?
            $recommendedProductIds = DB::table('transaction_details')
                ->whereIn('transaction_id', $transactionIds)
                ->where('product_id', '!=', $id) // Kecualikan produk itu sendiri
                ->select('product_id', DB::raw('count(*) as total_bought_together'))
                ->groupBy('product_id')
                ->orderByDesc('total_bought_together')
                ->take(4) // Ambil 4 produk teratas
                ->pluck('product_id');

            // 3. Tarik data lengkap produk rekomendasinya
            $recommendations = collect();
            if ($recommendedProductIds->isNotEmpty()) {
                // Gunakan FIELD agar urutan sesuai frekuensi pembelian terbanyak (bukan acak)
                $orderString = implode(',', $recommendedProductIds->toArray());
                $recommendations = Product::whereIn('id', $recommendedProductIds)
                    ->where('status', 'active')
                    ->orderByRaw("FIELD(id, $orderString)")
                    ->get();
            }

            // 4. FALLBACK LOGIC:
            // Jika produk ini masih sangat baru dan belum ada riwayat pembelian bersilangan (kurang dari 4),
            // isi kekosongannya dengan produk dari Kategori yang sama secara acak.
            if ($recommendations->count() < 4) {
                $product = Product::find($id);
                if ($product) {
                    $fallbackProducts = Product::where('category_id', $product->category_id)
                        ->where('id', '!=', $id)
                        ->whereNotIn('id', $recommendedProductIds) // Jangan duplikat
                        ->where('status', 'active')
                        ->inRandomOrder()
                        ->take(4 - $recommendations->count())
                        ->get();

                    $recommendations = $recommendations->merge($fallbackProducts);
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $recommendations
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // FUNGSI BARU: MENGAMBIL PRODUK DENGAN STOK KRITIS (<= 5)
    public function getLowStockProducts()
    {
        try {
            $products = Product::where('status', 'active')
                ->where('stock', '<=', 5)
                ->orderBy('stock', 'asc')
                ->get(['id', 'name', 'sku', 'stock', 'image_url']);

            return response()->json([
                'status' => 'success',
                'data' => $products,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // // =====================================================================
    // // 👇 FITUR PENCARIAN SUPER CEPAT (MEILISEARCH FULL-TEXT SEARCH) 👇
    // // =====================================================================
    // public function search(Request $request)
    // {
    //     try {
    //         $query = $request->query('q', '');

    //         // Jika query kosong, kembalikan response kosong agar Frontend tidak crash
    //         if (empty(trim($query))) {
    //             return response()->json(['status' => 'success', 'data' => []], 200);
    //         }

    //         // Eksekusi pencarian ke Meilisearch melalui Laravel Scout
    //         // Kami membatasi hasil maksimal 15 agar response tetap ringan
    //         $products = Product::search($query)
    //             ->where('status', 'active')
    //             ->take(15)
    //             ->get();

    //         // Meilisearch tidak secara otomatis membawa relasi category
    //         // Jadi kita load category-nya secara lazy (Eager Loading N+1 Prevention)
    //         $products->load('category');

    //         return response()->json(['status' => 'success', 'data' => $products], 200);

    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    //     }
    // }

    // =====================================================================
    // 👇 FITUR PENCARIAN SUPER CEPAT (MEILISEARCH FULL-TEXT SEARCH) 👇
    // =====================================================================
    public function search(Request $request)
    {
        try {
            $query = $request->query('q', '');

            if (empty(trim($query))) {
                return response()->json(['status' => 'success', 'data' => []], 200);
            }

            // 👇 [PERBAIKAN] Hapus ->where('status', 'active') di sini 👇
            $products = Product::search($query)
                ->take(15)
                ->get();

            $products->load('category');

            return response()->json(['status' => 'success', 'data' => $products], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
