<?php

// namespace App\Http\Controllers;

// use Carbon\Carbon;
// use App\Models\User;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Services\C45Service;
// use App\Models\TransactionDetail;
// use Illuminate\Support\Facades\DB;
// use App\Http\Controllers\Controller;

// class DashboardController extends Controller
// {
//     // =========================================================================
//     // MASTER ENDPOINT (SOLUSI ERROR MAX_USER_CONNECTIONS)
//     // =========================================================================
//     public function getDashboardMasterData(C45Service $c45Service)
//     {
//         return response()->json([
//             'stats'           => $this->fetchStatsData(),
//             'revenue'         => $this->fetchRevenueChartData(),
//             'popular'         => $this->fetchPopularProductsData(),
//             'predicted'       => $this->fetchPredictedBestsellersData($c45Service),
//             'activities'      => $this->fetchRecentActivitiesData(),
//             'daily'           => $this->fetchAverageDailyRevenueData(),
//             'returned'        => $this->fetchMostReturnedProducts(),
//             'peak_hours'      => $this->fetchPeakOrderHours(),
//             'top_affiliators' => $this->fetchTopAffiliators(),
//         ]);
//     }

//     // =========================================================================
//     // PRIVATE HELPER METHODS
//     // =========================================================================

//     private function fetchStatsData()
//     {
//         $currentMonthSales = Transaction::where('status', 'completed')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->whereYear('created_at', Carbon::now()->year)
//             ->sum('total_amount');

//         $lastMonthSales = Transaction::where('status', 'completed')
//             ->whereMonth('created_at', Carbon::now()->subMonth()->month)
//             ->whereYear('created_at', Carbon::now()->subMonth()->year)
//             ->sum('total_amount');

//         $salesGrowth = $lastMonthSales > 0 ? (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100 : 0;
//         $totalSalesAllTime = Transaction::where('status', 'completed')->sum('total_amount');

//         $totalProducts = Product::where('status', 'active')->count();
//         $newProductsThisMonth = Product::where('status', 'active')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->count();

//         $currentMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->month)->count();
//         $lastMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->subMonth()->month)->count();
//         $transactionGrowth = $lastMonthTransactions > 0 ? (($currentMonthTransactions - $lastMonthTransactions) / $lastMonthTransactions) * 100 : 0;
//         $totalTransactionsAllTime = Transaction::count();

//         $totalUsers = User::where('usertype', 'user')->count();
//         $newUsersThisMonth = User::where('usertype', 'user')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->count();

//         return [
//             'total_sales' => (float) $totalSalesAllTime,
//             'sales_growth' => round($salesGrowth, 1),
//             'total_products' => $totalProducts,
//             'new_products_growth' => $newProductsThisMonth,
//             'total_transactions' => $totalTransactionsAllTime,
//             'transaction_growth' => round($transactionGrowth, 1),
//             'total_users' => $totalUsers,
//             'new_users_growth' => $newUsersThisMonth,
//         ];
//     }

//     private function fetchRevenueChartData()
//     {
//         return Transaction::where('status', 'completed')
//             ->where('created_at', '>=', Carbon::now()->subMonths(6))
//             ->select(
//                 DB::raw('SUM(total_amount) as total'),
//                 DB::raw("DATE_FORMAT(created_at, '%b') as month"),
//                 DB::raw('MONTH(created_at) as month_num')
//             )
//             // [PERBAIKAN] Menggunakan groupByRaw agar aman dari MySQL Strict Mode
//             ->groupByRaw("DATE_FORMAT(created_at, '%b'), MONTH(created_at)")
//             ->orderByRaw("MONTH(created_at) ASC")
//             ->get()
//             ->toArray();
//     }

//     private function fetchPopularProductsData()
//     {
//         return TransactionDetail::select('products.name', DB::raw('SUM(transaction_details.quantity) as total_sold'))
//             ->join('products', 'products.id', '=', 'transaction_details.product_id')
//             ->groupBy('products.name')
//             ->orderBy('total_sold', 'DESC')
//             ->limit(5)
//             ->get()
//             ->toArray();
//     }

//     private function fetchPredictedBestsellersData(C45Service $c45Service)
//     {
//         // [PERBAIKAN] Kembali menggunakan withSum() karena jauh lebih aman
//         // dari error MySQL ONLY_FULL_GROUP_BY daripada menggunakan JOIN manual.
//         $products = Product::with('category')
//             ->withSum(['transactionDetails as total_sold' => function ($query) {
//                 $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//                     ->where('transactions.status', 'completed');
//             }], 'quantity')
//             ->where('status', 'active')
//             ->get();

//         if ($products->isEmpty()) {
//             return [];
//         }

//         $avgSold = $products->avg('total_sold') ?: 1;
//         $avgPrice = $products->avg('price') ?: 100000;

//         $dataset = [];
//         $predictData = [];

//         foreach ($products as $p) {
//             $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
//             $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
//             $hasDiscount = $p->discount_price ? 'Yes' : 'No';
//             $categoryName = $p->category->name ?? 'Unknown';

//             $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

//             $features = [
//                 'category' => $categoryName,
//                 'price_level' => $priceCategory,
//                 'is_discounted' => $hasDiscount,
//                 'stock_status' => $stockCategory,
//                 'label' => $label
//             ];

//             $dataset[] = $features;
//             $predictData[$p->id] = [
//                 'product' => $p,
//                 'features' => $features
//             ];
//         }

//         $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
//         $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

//         $results = [];

//         $formatImageUrl = function($imagePath) {
//             if (!$imagePath) return '';
//             if (str_starts_with($imagePath, 'http')) return $imagePath;

//             // [PERBAIKAN FATAL ERROR] Menggunakan config() alih-alih env()
//             $appUrl = config('app.url') ? config('app.url') : 'https://back.solher.co.id';
//             $baseUrlFixed = str_replace('/api', '', $appUrl);

//             return $baseUrlFixed . '/storage/' . $imagePath;
//         };

//         foreach ($predictData as $id => $data) {
//             $product = $data['product'];
//             $features = $data['features'];

//             $prediction = $c45Service->predict($decisionTree, $features);
//             $statusLabel = $prediction['label'];
//             $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

//             if ($statusLabel === 'Laris') {
//                 $results[] = [
//                     'id' => $product->id,
//                     'name' => $product->name,
//                     'image' => $formatImageUrl($product->image),
//                     'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
//                     'label' => 'High Potential (C4.5)',
//                     'color' => 'text-green-600',
//                     'score' => random_int(75, 100)
//                 ];
//             }
//         }

//         if (empty($results)) {
//             $fallback = $this->fetchPopularProductsData();
//             $formattedFallback = [];

//             foreach($fallback as $index => $item) {
//                 $prod = Product::where('name', $item['name'])->first();
//                 $dynamicScore = 96 - ($index * random_int(5, 8));

//                 $formattedFallback[] = [
//                     'id' => $prod ? $prod->id : random_int(1000, 9999),
//                     'name' => $item['name'],
//                     'image' => $prod ? $formatImageUrl($prod->image) : '',
//                     'reasons' => "Historical Best: Sold " . ($item['total_sold'] ?? 0) . " units (Fallback Mode).",
//                     'label' => 'Historical Best',
//                     'color' => 'text-blue-600',
//                     'score' => max(60, $dynamicScore)
//                 ];
//             }
//             return $formattedFallback;
//         }

//         usort($results, function ($a, $b) {
//             return $b['score'] <=> $a['score'];
//         });

//         return array_slice($results, 0, 100);
//     }

//     private function fetchRecentActivitiesData()
//     {
//         return Transaction::with('user:id,first_name,last_name,email')
//             ->select('id', 'order_id', 'user_id', 'total_amount', 'status', 'created_at')
//             ->latest()
//             ->limit(5)
//             ->get()
//             ->map(function ($transaction) {
//                 return [
//                     'id' => $transaction->id,
//                     'order_id' => $transaction->order_id,
//                     'customer' => $transaction->user ? $transaction->user->first_name . ' ' . $transaction->user->last_name : 'Guest',
//                     'amount' => $transaction->total_amount,
//                     'status' => $transaction->status,
//                     'time_ago' => $transaction->created_at->diffForHumans()
//                 ];
//             })->toArray();
//     }

//     private function fetchAverageDailyRevenueData()
//     {
//         $dailyAverages = Transaction::where('status', 'completed')
//             ->select(
//                 DB::raw('AVG(total_amount) as average_revenue'),
//                 DB::raw('DAYOFWEEK(created_at) as day_of_week')
//             )
//             // [PERBAIKAN] Memastikan groupBy menggunakan Raw Expression
//             ->groupByRaw('DAYOFWEEK(created_at)')
//             ->get();

//         $chartData = [
//             1 => ['day' => 'Mon', 'average' => 0],
//             2 => ['day' => 'Tue', 'average' => 0],
//             3 => ['day' => 'Wed', 'average' => 0],
//             4 => ['day' => 'Thu', 'average' => 0],
//             5 => ['day' => 'Fri', 'average' => 0],
//             6 => ['day' => 'Sat', 'average' => 0],
//             7 => ['day' => 'Sun', 'average' => 0],
//         ];

//         foreach ($dailyAverages as $data) {
//             $dbDay = $data->day_of_week;
//             $mappedDay = $dbDay == 1 ? 7 : $dbDay - 1;
//             $chartData[$mappedDay]['average'] = (float) $data->average_revenue;
//         }

//         return array_values($chartData);
//     }

//     // =========================================================================
//     // FUNGSI ANALITIK TAMBAHAN
//     // =========================================================================

//     private function fetchMostReturnedProducts()
//     {
//         return TransactionDetail::select('products.name', 'products.image', DB::raw('SUM(transaction_details.quantity) as total_returned'))
//             ->join('products', 'products.id', '=', 'transaction_details.product_id')
//             ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//             ->whereIn('transactions.status', ['refund_requested', 'refund_approved', 'refunded', 'returned', 'issues'])
//             ->groupBy('products.name', 'products.image')
//             ->orderBy('total_returned', 'DESC')
//             ->limit(5)
//             ->get()
//             ->map(function ($item) {
//                 // [PERBAIKAN FATAL ERROR]
//                 $appUrl = config('app.url') ? config('app.url') : 'https://back.solher.co.id';
//                 $baseUrlFixed = str_replace('/api', '', $appUrl);
//                 $imgUrl = $item->image && !str_starts_with($item->image, 'http') ? $baseUrlFixed . '/storage/' . $item->image : $item->image;

//                 return [
//                     'name' => $item->name,
//                     'image' => $imgUrl,
//                     'total_returned' => (int) $item->total_returned
//                 ];
//             })
//             ->toArray();
//     }

//     private function fetchPeakOrderHours()
//     {
//         $hourlyData = Transaction::select(
//                 DB::raw('HOUR(created_at) as hour'),
//                 DB::raw('COUNT(id) as total_orders')
//             )
//             // [PERBAIKAN] Menggunakan groupByRaw agar aman di MySQL server
//             ->groupByRaw('HOUR(created_at)')
//             ->orderByRaw('HOUR(created_at) ASC')
//             ->get()
//             ->keyBy('hour')
//             ->toArray();

//         $formatted = [];
//         for ($i = 0; $i < 24; $i++) {
//             $formatted[] = [
//                 'hour' => str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
//                 'orders' => isset($hourlyData[$i]) ? $hourlyData[$i]['total_orders'] : 0
//             ];
//         }

//         return $formatted;
//     }

//     private function fetchTopAffiliators()
//     {
//         return Transaction::select('users.first_name', 'users.last_name', 'users.email', 'users.profile_image', 'users.usertype', DB::raw('SUM(transactions.total_amount) as total_generated'), DB::raw('COUNT(transactions.id) as total_orders'))
//             ->join('users', 'users.id', '=', 'transactions.user_id')
//             ->where('transactions.status', 'completed')
//             ->whereIn('users.usertype', ['user', 'reseller', 'affiliate'])
//             ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.profile_image', 'users.usertype')
//             ->orderBy('total_generated', 'DESC')
//             ->limit(5)
//             ->get()
//             ->map(function ($user) {
//                 return [
//                     'name' => $user->first_name . ' ' . $user->last_name,
//                     'email' => $user->email,
//                     'image' => $user->profile_image,
//                     'usertype' => $user->usertype,
//                     'total_generated' => $user->total_generated,
//                     'total_orders' => $user->total_orders
//                 ];
//             })
//             ->toArray();
//     }

//     // =========================================================================
//     // ENDPOINTS LAMA (DI-WRAP AGAR TETAP BERFUNGSI JIKA ADA HALAMAN LAIN YG PAKAI)
//     // =========================================================================
//     public function getStats()
//     {
//         // --- 1. Total Sales & Growth ---
//         $currentMonthSales = Transaction::where('status', 'completed')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->whereYear('created_at', Carbon::now()->year)
//             ->sum('total_amount');

//         $lastMonthSales = Transaction::where('status', 'completed')
//             ->whereMonth('created_at', Carbon::now()->subMonth()->month)
//             ->whereYear('created_at', Carbon::now()->subMonth()->year)
//             ->sum('total_amount');

//         $salesGrowth = $lastMonthSales > 0 ? (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100 : 0;
//         $totalSalesAllTime = Transaction::where('status', 'completed')->sum('total_amount');

//         // --- 2. Total Products & Growth ---
//         $totalProducts = Product::where('status', 'active')->count();
//         $newProductsThisMonth = Product::where('status', 'active')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->count();

//         // --- 3. Total Transactions & Growth ---
//         $currentMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->month)->count();
//         $lastMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->subMonth()->month)->count();
//         $transactionGrowth = $lastMonthTransactions > 0 ? (($currentMonthTransactions - $lastMonthTransactions) / $lastMonthTransactions) * 100 : 0;
//         $totalTransactionsAllTime = Transaction::count();

//         // --- 4. Total Users & Growth ---
//         $totalUsers = User::where('usertype', 'user')->count();
//         $newUsersThisMonth = User::where('usertype', 'user')
//             ->whereMonth('created_at', Carbon::now()->month)
//             ->count();

//         return response()->json([
//             'total_sales' => (float) $totalSalesAllTime,
//             'sales_growth' => round($salesGrowth, 1),

//             'total_products' => $totalProducts,
//             'new_products_growth' => $newProductsThisMonth, // Angka mutlak bulan ini

//             'total_transactions' => $totalTransactionsAllTime,
//             'transaction_growth' => round($transactionGrowth, 1),

//             'total_users' => $totalUsers,
//             'new_users_growth' => $newUsersThisMonth, // Angka mutlak bulan ini
//         ]);
//     }

//     public function getRevenueChart()
//     {
//         // Ambil data pendapatan 6 bulan terakhir
//         $data = Transaction::where('status', 'completed')
//             ->where('created_at', '>=', Carbon::now()->subMonths(6))
//             ->select(
//                 DB::raw('SUM(total_amount) as total'),
//                 DB::raw("DATE_FORMAT(created_at, '%b') as month"),
//                 DB::raw('MONTH(created_at) as month_num')
//             )
//             ->groupBy('month', 'month_num')
//             ->orderBy('month_num', 'ASC')
//             ->get();

//         return response()->json($data);
//     }

//     public function getPopularProducts()
//     {
//         // Ambil 5 produk teratas berdasarkan total quantity yang terjual
//         $popular = TransactionDetail::select('products.name', DB::raw('SUM(transaction_details.quantity) as total_sold'))
//             ->join('products', 'products.id', '=', 'transaction_details.product_id')
//             ->groupBy('products.name')
//             ->orderBy('total_sold', 'DESC')
//             ->limit(5)
//             ->get();

//         return response()->json($popular);
//     }

//     // public function getPredictedBestsellers(C45Service $c45Service)
//     // {
//     //     // 1. AMBIL SEMUA PRODUK UNTUK DATA TRAINING & PREDIKSI
//     //     // Sertakan sum total qty terjual dari transaksi yang "completed"
//     //     $products = Product::with('category')
//     //         ->withSum(['transactionDetails as total_sold' => function ($query) {
//     //             $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//     //                 ->where('transactions.status', 'completed');
//     //         }], 'quantity')
//     //         ->where('status', 'active')
//     //         ->get();

//     //     if ($products->isEmpty()) {
//     //         return response()->json([]);
//     //     }

//     //     // Hitung batas "Laris" (Bestseller). Misalnya: Laris jika terjual di atas rata-rata.
//     //     $avgSold = $products->avg('total_sold') ?: 1;
//     //     $avgPrice = $products->avg('price') ?: 100000;

//     //     // 2. DISKRETISASI DATA (Ubah data numerik menjadi kategori/teks untuk algoritma C4.5)
//     //     $dataset = [];
//     //     $predictData = [];

//     //     foreach ($products as $p) {
//     //         // Feature Engineering
//     //         $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
//     //         $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
//     //         $hasDiscount = $p->discount_price ? 'Yes' : 'No';
//     //         $categoryName = $p->category->name ?? 'Unknown';

//     //         // Target Class (Label): Laris / Tidak Laris
//     //         $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

//     //         // Array ini yang akan dipakai mesin C4.5 untuk belajar
//     //         $features = [
//     //             'category' => $categoryName,
//     //             'price_level' => $priceCategory,
//     //             'is_discounted' => $hasDiscount,
//     //             'stock_status' => $stockCategory,
//     //             'label' => $label
//     //         ];

//     //         $dataset[] = $features;

//     //         // Simpan data asli untuk di-mapping kembali saat prediksi
//     //         $predictData[$p->id] = [
//     //             'product' => $p,
//     //             'features' => $features
//     //         ];
//     //     }

//     //     // 3. PROSES PEMBELAJARAN MESIN (TRAINING)
//     //     $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
//     //     $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

//     //     // 4. PROSES PREDIKSI & PENYUSUNAN RESPONSE UNTUK VUE
//     //     $results = [];

//     //     foreach ($predictData as $id => $data) {
//     //         $product = $data['product'];
//     //         $features = $data['features'];

//     //         // Lakukan prediksi menggunakan model C4.5 yang terbentuk
//     //         $prediction = $c45Service->predict($decisionTree, $features);

//     //         $statusLabel = $prediction['label'];
//     //         $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

//     //         // Jika sistem memprediksi Laris, masukkan ke daftar hasil
//     //         if ($statusLabel === 'Laris') {
//     //             $results[] = [
//     //                 'id' => $product->id,
//     //                 'name' => $product->name,
//     //                 'image' => $product->image,
//     //                 // Karena ini Machine Learning sungguhan, kita tampilkan Rules yang memicu keputusan tersebut
//     //                 'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
//     //                 'label' => 'High Potential (C4.5)',
//     //                 'color' => 'text-green-600',
//     //                 'score' => random_int(85, 98) // Mocked confidence score, as standard Decision Trees output strict classes
//     //             ];
//     //         }
//     //     }

//     //     // Jika hasilnya kosong (tidak ada yang diprediksi laris), ambil 4 terbaik secara historis
//     //     if (empty($results)) {
//     //         return $this->getPopularProducts();
//     //     }

//     //     // Ambil 4 teratas
//     //     return response()->json(array_slice($results, 0, 4));
//     // }

//     public function getPredictedBestsellers(C45Service $c45Service)
//     {
//         // 1. AMBIL SEMUA PRODUK UNTUK DATA TRAINING & PREDIKSI (CARA YANG LEBIH AMAN)
//         // Kita menggunakan leftJoin agar produk yang belum laku (null) tetap terambil sebagai 0.
//         $products = Product::with('category')
//             ->select('products.*', DB::raw('COALESCE(SUM(transaction_details.quantity), 0) as total_sold'))
//             ->leftJoin('transaction_details', 'products.id', '=', 'transaction_details.product_id')
//             ->leftJoin('transactions', function ($join) {
//                 $join->on('transaction_details.transaction_id', '=', 'transactions.id')
//                     ->where('transactions.status', '=', 'completed');
//             })
//             ->where('products.status', 'active')
//             ->groupBy('products.id') // Wajib di-group berdasarkan ID produk
//             ->get();

//         if ($products->isEmpty()) {
//             return response()->json([]);
//         }

//         // Hitung batas "Laris" (Bestseller). Misalnya: Laris jika terjual di atas rata-rata.
//         $avgSold = $products->avg('total_sold') ?: 1;
//         $avgPrice = $products->avg('price') ?: 100000;

//         // 2. DISKRETISASI DATA (Ubah data numerik menjadi kategori/teks untuk algoritma C4.5)
//         $dataset = [];
//         $predictData = [];

//         foreach ($products as $p) {
//             // Feature Engineering
//             $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
//             $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
//             $hasDiscount = $p->discount_price ? 'Yes' : 'No';
//             $categoryName = $p->category->name ?? 'Unknown';

//             // Target Class (Label): Laris / Tidak Laris
//             // Perhatikan bahwa $p->total_sold sekarang adalah hasil dari DB::raw di atas
//             $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

//             // Array ini yang akan dipakai mesin C4.5 untuk belajar
//             $features = [
//                 'category' => $categoryName,
//                 'price_level' => $priceCategory,
//                 'is_discounted' => $hasDiscount,
//                 'stock_status' => $stockCategory,
//                 'label' => $label
//             ];

//             $dataset[] = $features;

//             // Simpan data asli untuk di-mapping kembali saat prediksi
//             $predictData[$p->id] = [
//                 'product' => $p,
//                 'features' => $features
//             ];
//         }

//         // 3. PROSES PEMBELAJARAN MESIN (TRAINING)
//         $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
//         $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

//         // 4. PROSES PREDIKSI & PENYUSUNAN RESPONSE UNTUK VUE
//         $results = [];

//         foreach ($predictData as $id => $data) {
//             $product = $data['product'];
//             $features = $data['features'];

//             // Lakukan prediksi menggunakan model C4.5 yang terbentuk
//             $prediction = $c45Service->predict($decisionTree, $features);

//             $statusLabel = $prediction['label'];
//             $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

//             // Jika sistem memprediksi Laris, masukkan ke daftar hasil
//             if ($statusLabel === 'Laris') {
//                 $results[] = [
//                     'id' => $product->id,
//                     'name' => $product->name,
//                     'image' => $product->image,
//                     'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
//                     'label' => 'High Potential (C4.5)',
//                     'color' => 'text-green-600',
//                     'score' => random_int(75, 100)
//                 ];
//             }
//         }

//         // Jika hasilnya kosong, ambil 4 terbaik secara historis
//         if (empty($results)) {
//             return $this->getPopularProducts();
//         }

//         // Ambil 100 teratas
//         return response()->json(array_slice($results, 0, 100));
//     }


//     // =========================================================================
//     // [BARU] FUNGSI UNTUK RECENT ACTIVITIES (LIVE FEED)
//     // =========================================================================
//     public function getRecentActivities()
//     {
//         // Mengambil 5 transaksi terbaru dari semua status
//         $recentTransactions = Transaction::with('user:id,first_name,last_name,email')
//             ->select('id', 'order_id', 'user_id', 'total_amount', 'status', 'created_at')
//             ->latest()
//             ->limit(5)
//             ->get()
//             ->map(function ($transaction) {
//                 return [
//                     'id' => $transaction->id,
//                     'order_id' => $transaction->order_id,
//                     'customer' => $transaction->user ? $transaction->user->first_name . ' ' . $transaction->user->last_name : 'Guest',
//                     'amount' => $transaction->total_amount,
//                     'status' => $transaction->status,
//                     'time_ago' => $transaction->created_at->diffForHumans()
//                 ];
//             });

//         return response()->json($recentTransactions);
//     }

//     // =========================================================================
//     // [BARU] FUNGSI UNTUK GRAFIK RATA-RATA PENDAPATAN HARIAN (SENIN - MINGGU)
//     // =========================================================================
//     public function getAverageDailyRevenue()
//     {
//         // Query menggunakan MySQL DAYOFWEEK()
//         // DAYOFWEEK() mengembalikan: 1 = Minggu, 2 = Senin, 3 = Selasa, ... 7 = Sabtu
//         // Kita menggunakan AVG() untuk mendapatkan rata-rata, bukan total, agar datanya tidak bias jika bulan tertentu punya lebih banyak hari Senin.

//         $dailyAverages = Transaction::where('status', 'completed')
//             ->select(
//                 DB::raw('AVG(total_amount) as average_revenue'),
//                 DB::raw('DAYOFWEEK(created_at) as day_of_week')
//             )
//             ->groupBy('day_of_week')
//             ->get();

//         // Siapkan struktur data default (Senin - Minggu dengan nilai 0)
//         // Kita ubah index agar sesuai dengan hari kalender internasional (Senin = 1, Minggu = 7)
//         $chartData = [
//             1 => ['day' => 'Mon', 'average' => 0],
//             2 => ['day' => 'Tue', 'average' => 0],
//             3 => ['day' => 'Wed', 'average' => 0],
//             4 => ['day' => 'Thu', 'average' => 0],
//             5 => ['day' => 'Fri', 'average' => 0],
//             6 => ['day' => 'Sat', 'average' => 0],
//             7 => ['day' => 'Sun', 'average' => 0],
//         ];

//         // Mapping hasil query database ke struktur chartData
//         foreach ($dailyAverages as $data) {
//             // Konversi dari DAYOFWEEK MySQL (1=Sun, 2=Mon... 7=Sat)
//             // ke Array kita (1=Mon, 2=Tue... 7=Sun)
//             $dbDay = $data->day_of_week;

//             if ($dbDay == 1) {
//                 $mappedDay = 7; // Minggu
//             } else {
//                 $mappedDay = $dbDay - 1; // Senin - Sabtu (2-1=1, 7-1=6)
//             }

//             $chartData[$mappedDay]['average'] = (float) $data->average_revenue;
//         }

//         // Kembalikan array value-nya saja (tanpa key 1-7) agar mudah dibaca oleh Frontend
//         return response()->json(array_values($chartData));
//     }
// }

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\C45Service;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    // =========================================================================
    // MASTER ENDPOINT (SOLUSI ERROR MAX_USER_CONNECTIONS)
    // =========================================================================
    public function getDashboardMasterData(C45Service $c45Service)
    {
        return response()->json([
            'stats'           => $this->fetchStatsData(),
            'revenue'         => $this->fetchRevenueChartData(),
            'popular'         => $this->fetchPopularProductsData(),
            'predicted'       => $this->fetchPredictedBestsellersData($c45Service),
            'activities'      => $this->fetchRecentActivitiesData(),
            'daily'           => $this->fetchAverageDailyRevenueData(),
            'returned'        => $this->fetchMostReturnedProducts(),
            'peak_hours'      => $this->fetchPeakOrderHours(),
            'top_affiliators' => $this->fetchTopAffiliators(),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    private function fetchStatsData()
    {
        $currentMonthSales = Transaction::where('status', 'completed')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('total_amount');

        $lastMonthSales = Transaction::where('status', 'completed')
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->sum('total_amount');

        $salesGrowth = $lastMonthSales > 0 ? (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100 : 0;
        $totalSalesAllTime = Transaction::where('status', 'completed')->sum('total_amount');

        $totalProducts = Product::where('status', 'active')->count();
        $newProductsThisMonth = Product::where('status', 'active')
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();

        $currentMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->month)->count();
        $lastMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->subMonth()->month)->count();
        $transactionGrowth = $lastMonthTransactions > 0 ? (($currentMonthTransactions - $lastMonthTransactions) / $lastMonthTransactions) * 100 : 0;
        $totalTransactionsAllTime = Transaction::count();

        $totalUsers = User::where('usertype', 'user')->count();
        $newUsersThisMonth = User::where('usertype', 'user')
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();

        return [
            'total_sales' => (float) $totalSalesAllTime,
            'sales_growth' => round($salesGrowth, 1),
            'total_products' => $totalProducts,
            'new_products_growth' => $newProductsThisMonth,
            'total_transactions' => $totalTransactionsAllTime,
            'transaction_growth' => round($transactionGrowth, 1),
            'total_users' => $totalUsers,
            'new_users_growth' => $newUsersThisMonth,
        ];
    }

    private function fetchRevenueChartData()
    {
        return Transaction::where('status', 'completed')
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->select(
                DB::raw('SUM(total_amount) as total'),
                DB::raw("DATE_FORMAT(created_at, '%b') as month"),
                DB::raw('MONTH(created_at) as month_num')
            )
            ->groupByRaw("DATE_FORMAT(created_at, '%b'), MONTH(created_at)")
            ->orderByRaw("MONTH(created_at) ASC")
            ->get()
            ->toArray();
    }

    private function fetchPopularProductsData()
    {
        return TransactionDetail::select('products.name', DB::raw('SUM(transaction_details.quantity) as total_sold'))
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->groupBy('products.name')
            ->orderBy('total_sold', 'DESC')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function fetchPredictedBestsellersData(C45Service $c45Service)
    {
        // [PERBAIKAN TUNTAS] Menggunakan Subquery Select.
        // Tidak butuh relasi di model Product dan aman dari error MySQL GROUP BY.
        $products = Product::with('category')
            ->select('products.*')
            ->selectSub(function ($query) {
                $query->selectRaw('COALESCE(SUM(transaction_details.quantity), 0)')
                      ->from('transaction_details')
                      ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                      ->whereColumn('transaction_details.product_id', 'products.id')
                      ->where('transactions.status', 'completed');
            }, 'total_sold')
            ->where('status', 'active')
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $avgSold = $products->avg('total_sold') ?: 1;
        $avgPrice = $products->avg('price') ?: 100000;

        $dataset = [];
        $predictData = [];

        foreach ($products as $p) {
            $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
            $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
            $hasDiscount = $p->discount_price ? 'Yes' : 'No';
            $categoryName = $p->category->name ?? 'Unknown';

            $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

            $features = [
                'category' => $categoryName,
                'price_level' => $priceCategory,
                'is_discounted' => $hasDiscount,
                'stock_status' => $stockCategory,
                'label' => $label
            ];

            $dataset[] = $features;
            $predictData[$p->id] = [
                'product' => $p,
                'features' => $features
            ];
        }

        $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
        $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

        $results = [];

        $formatImageUrl = function($imagePath) {
            if (!$imagePath) return '';
            if (str_starts_with($imagePath, 'http')) return $imagePath;

            $appUrl = config('app.url') ? config('app.url') : 'https://back.gycoraessence.com';
            $baseUrlFixed = str_replace('/api', '', $appUrl);

            return $baseUrlFixed . '/storage/' . $imagePath;
        };

        foreach ($predictData as $id => $data) {
            $product = $data['product'];
            $features = $data['features'];

            $prediction = $c45Service->predict($decisionTree, $features);
            $statusLabel = $prediction['label'];
            $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

            if ($statusLabel === 'Laris') {
                $results[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'image' => $formatImageUrl($product->image),
                    'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
                    'label' => 'High Potential (C4.5)',
                    'color' => 'text-green-600',
                    'score' => random_int(75, 100)
                ];
            }
        }

        if (empty($results)) {
            $fallback = $this->fetchPopularProductsData();
            $formattedFallback = [];

            foreach($fallback as $index => $item) {
                $prod = Product::where('name', $item['name'])->first();
                $dynamicScore = 96 - ($index * random_int(5, 8));

                $formattedFallback[] = [
                    'id' => $prod ? $prod->id : random_int(1000, 9999),
                    'name' => $item['name'],
                    'image' => $prod ? $formatImageUrl($prod->image) : '',
                    'reasons' => "Historical Best: Sold " . ($item['total_sold'] ?? 0) . " units (Fallback Mode).",
                    'label' => 'Historical Best',
                    'color' => 'text-blue-600',
                    'score' => max(60, $dynamicScore)
                ];
            }
            return $formattedFallback;
        }

        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, 100);
    }

    private function fetchRecentActivitiesData()
    {
        return Transaction::with('user:id,first_name,last_name,email')
            ->select('id', 'order_id', 'user_id', 'total_amount', 'status', 'created_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'order_id' => $transaction->order_id,
                    'customer' => $transaction->user ? $transaction->user->first_name . ' ' . $transaction->user->last_name : 'Guest',
                    'amount' => $transaction->total_amount,
                    'status' => $transaction->status,
                    'time_ago' => $transaction->created_at->diffForHumans()
                ];
            })->toArray();
    }

    private function fetchAverageDailyRevenueData()
    {
        $dailyAverages = Transaction::where('status', 'completed')
            ->select(
                DB::raw('AVG(total_amount) as average_revenue'),
                DB::raw('DAYOFWEEK(created_at) as day_of_week')
            )
            ->groupByRaw('DAYOFWEEK(created_at)')
            ->get();

        $chartData = [
            1 => ['day' => 'Mon', 'average' => 0],
            2 => ['day' => 'Tue', 'average' => 0],
            3 => ['day' => 'Wed', 'average' => 0],
            4 => ['day' => 'Thu', 'average' => 0],
            5 => ['day' => 'Fri', 'average' => 0],
            6 => ['day' => 'Sat', 'average' => 0],
            7 => ['day' => 'Sun', 'average' => 0],
        ];

        foreach ($dailyAverages as $data) {
            $dbDay = $data->day_of_week;
            $mappedDay = $dbDay == 1 ? 7 : $dbDay - 1;
            $chartData[$mappedDay]['average'] = (float) $data->average_revenue;
        }

        return array_values($chartData);
    }

    // =========================================================================
    // FUNGSI ANALITIK TAMBAHAN
    // =========================================================================

    private function fetchMostReturnedProducts()
    {
        return TransactionDetail::select('products.name', 'products.image_url', DB::raw('SUM(transaction_details.quantity) as total_returned'))
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->whereIn('transactions.status', ['refund_requested', 'refund_approved', 'refunded', 'returned', 'issues'])
            ->groupBy('products.name', 'products.image_url')
            ->orderBy('total_returned', 'DESC')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $appUrl = config('app.url') ? config('app.url') : 'https://back.gycoraessence.com';
                $baseUrlFixed = str_replace('/api', '', $appUrl);
                $imgUrl = $item->image && !str_starts_with($item->image, 'http') ? $baseUrlFixed . '/storage/' . $item->image : $item->image;

                return [
                    'name' => $item->name,
                    'image' => $imgUrl,
                    'total_returned' => (int) $item->total_returned
                ];
            })
            ->toArray();
    }

    private function fetchPeakOrderHours()
    {
        $hourlyData = Transaction::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(id) as total_orders')
            )
            ->groupByRaw('HOUR(created_at)')
            ->orderByRaw('HOUR(created_at) ASC')
            ->get()
            ->keyBy('hour')
            ->toArray();

        $formatted = [];
        for ($i = 0; $i < 24; $i++) {
            $formatted[] = [
                'hour' => str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'orders' => isset($hourlyData[$i]) ? $hourlyData[$i]['total_orders'] : 0
            ];
        }

        return $formatted;
    }

    private function fetchTopAffiliators()
    {
        return Transaction::select('users.first_name', 'users.last_name', 'users.email', 'users.profile_image', 'users.usertype', DB::raw('SUM(transactions.total_amount) as total_generated'), DB::raw('COUNT(transactions.id) as total_orders'))
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->where('transactions.status', 'completed')
            ->whereIn('users.usertype', ['user', 'reseller', 'affiliate'])
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.profile_image', 'users.usertype')
            ->orderBy('total_generated', 'DESC')
            ->limit(5)
            ->get()
            ->map(function ($user) {
                return [
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'image' => $user->profile_image,
                    'usertype' => $user->usertype,
                    'total_generated' => $user->total_generated,
                    'total_orders' => $user->total_orders
                ];
            })
            ->toArray();
    }

    // =========================================================================
    // 👇 [BARU] FUNGSI AI BUSINESS ANALYST & TREND FORECASTER 👇
    // =========================================================================
    public function getAiInsights()
    {
        try {
            // 1. Tarik Data Penjualan 7 Hari Terakhir vs 7 Hari Sebelumnya
            $last7Days = Carbon::now()->subDays(7);

            $salesLast7Days = Transaction::where('status', 'completed')
                ->where('created_at', '>=', $last7Days)
                ->sum('total_amount');

            $previous7Days = Carbon::now()->subDays(14);
            $salesPrevious7Days = Transaction::where('status', 'completed')
                ->where('created_at', '>=', $previous7Days)
                ->where('created_at', '<', $last7Days)
                ->sum('total_amount');

            // 2. Tarik Data Stok Menipis (< 10)
            $lowStockProducts = Product::where('status', 'active')
                ->where('stock', '<=', 10)
                ->select('name', 'stock')
                ->get();

            // 3. Tarik Top 5 Produk Terlaris 7 Hari Terakhir
            $topSellingProducts = TransactionDetail::select('products.name', DB::raw('SUM(transaction_details.quantity) as total_sold'))
                ->join('products', 'products.id', '=', 'transaction_details.product_id')
                ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                ->where('transactions.status', 'completed')
                ->where('transactions.created_at', '>=', $last7Days)
                ->groupBy('products.name')
                ->orderBy('total_sold', 'DESC')
                ->limit(5)
                ->get();

            // 4. Racik Konteks untuk Prompt Gemini AI
            $dataContext = "Data Penjualan 7 Hari Terakhir: Rp " . number_format($salesLast7Days, 0, ',', '.') . "\n";
            $dataContext .= "Data Penjualan 7 Hari Sebelumnya: Rp " . number_format($salesPrevious7Days, 0, ',', '.') . "\n";

            $dataContext .= "\nProduk Stok Menipis (<= 10):\n";
            foreach ($lowStockProducts as $p) {
                $dataContext .= "- {$p->name} (Sisa Stok: {$p->stock})\n";
            }

            $dataContext .= "\nTop 5 Produk Terlaris (7 Hari Terakhir):\n";
            foreach ($topSellingProducts as $p) {
                $dataContext .= "- {$p->name} (Terjual: {$p->total_sold})\n";
            }

            // 5. System Instructions untuk Memaksa Format HTML dari AI
            $systemInstruction = "Kamu adalah AI Business Analyst & Trend Forecaster untuk Gycora. Berdasarkan data real-time yang diberikan, berikan 2 analisis strategis:\n1. Smart Restock Alert: Peringatan restock berdasarkan stok menipis dan kaitan dengan produk terlaris.\n2. Sales Summary: Evaluasi performa penjualan 7 hari terakhir vs sebelumnya.\n\nATURAN MUTLAK:\n- Buat laporan dalam format tag HTML rapi (seperti <strong>, <ul>, <li>, <br>). JANGAN menggunakan format Markdown (* atau **).\n- Jangan sertakan tag <html> atau <body>.\n- Gunakan bahasa Indonesia yang profesional, memotivasi, dan langsung pada kesimpulan.";

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => "Tolong analisis data berikut:\n" . $dataContext]]]
                ],
                'generationConfig' => ['temperature' => 0.4]
            ];

            // Tembak API Gemini
            $response = Http::timeout(30)->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Hilangkan backticks markdown jika Gemini membandel
                $text = preg_replace('/```html\n?/', '', $text);$text = preg_replace('/```/', '', $text);

                return response()->json(['status' => 'success', 'data' => trim($text)]);
            }

            return response()->json(['status' => 'error', 'message' => 'Gagal memproses API AI.'], 500);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AI Insights Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // Jangan lupa import: use Illuminate\Support\Facades\DB;

    public function getABTestResults()
    {
        $results = DB::table('transactions')
            ->select('ab_test_variant as variant', DB::raw('count(*) as total_checkouts'), DB::raw('sum(total_amount) as total_revenue'))
            ->whereIn('status', ['paid', 'processing', 'shipped', 'completed']) // Hanya transaksi sukses
            ->whereNotNull('ab_test_variant')
            ->groupBy('ab_test_variant')
            ->get();

        return response()->json($results);
    }
}
