<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryCoaController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\ConsultController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferReceivePaymentController;
use App\Http\Controllers\WishlistController;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

// --- RUTE PUBLIK (Tanpa Token) ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Admin Login diletakkan terpisah
// Route::post('/admin/login', [AuthController::class, 'adminLogin']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

// Lupa Password (User)
Route::post('/forgot-password/send-code', [AuthController::class, 'sendResetCode']);
Route::post('/forgot-password/verify-code', [AuthController::class, 'verifyResetCode']);
Route::post('/forgot-password/reset', [AuthController::class, 'resetPassword']);

// Lupa Password (Admin/Staf)
Route::post('/admin/forgot-password/send-code', [AuthController::class, 'adminSendResetCode']);
Route::post('/admin/forgot-password/verify-code', [AuthController::class, 'adminVerifyResetCode']);
Route::post('/admin/forgot-password/reset', [AuthController::class, 'adminResetPassword']);

// POST /api/contact
Route::post('/contact', [ContactController::class, 'store']);
Route::post('/subscribe', [ContactController::class, 'subscribe']);

Route::get('/categories', [CategoryController::class, 'index']);

// =========================================================================
// RUTE KATALOG PRODUK (PUBLIK)
// Siapapun bisa melihat daftar produk dan detail produk tanpa login
// =========================================================================
// Route::get('/products', [ProductController::class, 'index']);
// Route::get('/products/{id}', [ProductController::class, 'show']);

Route::get('/products', [ProductController::class, 'index']);

Route::get('/products/inactive', [ProductController::class, 'inactiveProducts']);

Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::post('/products/presigned-url', [ProductController::class, 'getPresignedUrl'])->middleware('auth:sanctum');
Route::get('/products/{slug}/variants', [ProductController::class, 'getRelatedVariants']);

Route::post('/biteship/callback', [TransactionController::class, 'biteshipCallback']);
Route::post('/payments/callback', [PaymentController::class, 'callback']);

Route::post('/promo/claim', [PromoController::class, 'claim']);

// Rute Publik (Bisa diakses Guest / Tanpa Login)
Route::get('/landing-page/consult', [ConsultController::class, 'getConsultPageData']);

// Rute Publik (Bisa dilihat siapa saja di halaman detail produk)
// Route::get('/products/{product}/reviews', [ReviewController::class, 'getProductReviews']);
Route::get('/products/{slug}/reviews', [ReviewController::class, 'getProductReviews']);

// Public Route
Route::get('/events', [EventController::class, 'index']);

Route::get('/search', [SearchController::class, 'globalSearch']);

// --- PROTECTED ROUTES (Butuh Token Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Profil & Users
    Route::get('/admin/users', [AuthController::class, 'getAllUsers']); // Idealnya dibungkus middleware admin lagi
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile', [AuthController::class, 'updateProfileInfo']);
    // --- TAMBAHKAN RUTE INI UNTUK UPLOAD FOTO PROFIL ---
    Route::post('/profile/image', [AuthController::class, 'updateImage']);
    // Rute untuk ubah password (jika Anda memakainya)
    Route::put('/profile/password', [AuthController::class, 'updatePassword']);
    Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);
    Route::post('/admin/update-info', [AuthController::class, 'updateAdminProfileInfo']);
    Route::post('/admin/presigned-url', [AuthController::class, 'getProfilePresignedUrl']);
    Route::post('/admin/update-image', [AuthController::class, 'updateAdminImage']);
    Route::post('/admin/update-password', [AuthController::class, 'updateAdminPassword']);

    Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);
    Route::get('/admin/messages/{id}', [ContactController::class, 'showAdminMessage']);
    Route::post('/admin/messages/{id}/respond', [ContactController::class, 'respondMessage']);
    Route::get('/user/contact-history', [ContactController::class, 'userHistory']);

    // Addresses
    // Anda bisa mendefinisikannya satu-satu untuk mencerminkan Golang Mux:
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    // ATAU, menggunakan cara instan Laravel (Sangat disarankan):
    // Route::apiResource('addresses', AddressController::class)->except(['show']);

    // Categories (Admin)
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // // Profil Pengguna (User biasa)
    // Route::put('/profile', [AuthController::class, 'updateProfile']);

    // // Rute Khusus Admin (Dalam praktiknya, Anda mungkin ingin menambahkan middleware role khusus untuk ini)
    // Route::get('/admin/users', [AuthController::class, 'getAllUsers']);

    // Route::post('/products', [ProductController::class, 'store']);
    // Route::put('/products/{id}', [ProductController::class, 'update']);
    // Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::delete('/products/{id}/force', [ProductController::class, 'forceDelete']);
    Route::get('/products/alerts/low-stock', [ProductController::class, 'getLowStockProducts']);

    Route::get('/admin/product-stocks', [ProductStockController::class, 'index']);
    Route::post('/admin/product-stocks/{productId}', [ProductStockController::class, 'store']);

    // =========================================================================
    // RUTE KERANJANG (CART)
    // =========================================================================
    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::put('/carts/{id}', [CartController::class, 'update']);
    Route::delete('/carts/{id}', [CartController::class, 'destroy']);

    Route::post('/checkout', [TransactionController::class, 'checkout']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancelOrder']);
    Route::post('/transactions/{id}/confirm', [TransactionController::class, 'confirmComplete']);
    Route::post('/transactions/{id}/refund-request', [TransactionController::class, 'requestRefund']);
    Route::post('/transactions/{id}/refund-process', [TransactionController::class, 'processRefundUser']);
    Route::get('/transactions/{id}/tracking', [TransactionController::class, 'trackOrder']);
    Route::post('/transactions/tracking/bulk', [TransactionController::class, 'bulkTrackOrders']);

    Route::get('/admin/transactions', [TransactionController::class, 'allTransactions']);
    Route::get('/admin/transactions/{id}', [TransactionController::class, 'adminShow']);
    Route::post('/admin/transactions/tracking/bulk', [TransactionController::class, 'adminBulkTrackOrders']);
    Route::get('/admin/transactions/{id}/tracking', [TransactionController::class, 'adminTrackOrder']);
    Route::get('/admin/transactions/{id}/print-label', [TransactionController::class, 'printLabel']);

    Route::get('/admin/sales-report', [TransactionController::class, 'salesReport']);

    // Approval Refund (Berurusan dengan uang keluar)
    Route::post('/admin/transactions/{id}/refund-approve', [TransactionController::class, 'approveRefund']);
    Route::post('/admin/transactions/{id}/refund-reject', [TransactionController::class, 'rejectRefund']);

    Route::post('/payments/invoice', [PaymentController::class, 'createInvoice']);
    Route::post('/shipping/rates', [PaymentController::class, 'getShippingRates']);

    Route::get('/wishlists', [WishlistController::class, 'index']);

    Route::post('/wishlists/toggle', [WishlistController::class, 'toggle']);

    Route::get('/admin/subscribers', function () {
        return response()->json(Subscriber::latest()->get());
    });

    // Modul Accounting Khusus
    Route::apiResource('/admin/category-coas', CategoryCoaController::class);
    Route::apiResource('/admin/coas', CoaController::class);
    Route::post('/admin/coas/{id}/post', [CoaController::class, 'postCoa']);
    Route::apiResource('/admin/payments', TransferReceivePaymentController::class);

    Route::apiResource('/admin/suppliers', InvoiceController::class)->except(['create', 'edit', 'show']);
    Route::get('/admin/suppliers', [InvoiceController::class, 'indexSupplier']);
    Route::post('/admin/suppliers', [InvoiceController::class, 'storeSupplier']);
    Route::put('/admin/suppliers/{id}', [InvoiceController::class, 'updateSupplier']);
    Route::delete('/admin/suppliers/{id}', [InvoiceController::class, 'deleteSupplier']);
    Route::get('/admin/invoices', [InvoiceController::class, 'indexInvoice']);
    Route::post('/admin/invoices', [InvoiceController::class, 'storeInvoice']);
    Route::put('/admin/invoices/{id}', [InvoiceController::class, 'updateInvoice']);
    Route::post('/admin/invoices/{id}/pay', [InvoiceController::class, 'processPayment']);
    Route::delete('/admin/invoices/{id}', [InvoiceController::class, 'deleteInvoice']);

    // Route::post('/promo/verify', [PromoController::class, 'verify']);
    Route::post('/promo/verify', [PromoController::class, 'verify'])->middleware('throttle:5,1');

    // Rute untuk User (Halaman ConsultWithUs.tsx)
    Route::post('/consultation/log', [ConsultController::class, 'logConsultation']);
    Route::post('/clinic/appointment', [ConsultController::class, 'bookAppointment']);

    // Rute untuk Admin (Halaman AdminTreatments.tsx) mengambil data notifikasi
    Route::get('/admin/clinic-notifications', [ConsultController::class, 'getAdminNotifications']);

    // Rute CRUD khusus Admin untuk mengelola Clinic Treatments
    Route::get('/admin/clinic-treatments', [ConsultController::class, 'indexAdmin']);
    Route::post('/admin/clinic-treatments', [ConsultController::class, 'storeAdmin']);
    Route::get('/admin/clinic-treatments/{id}', [ConsultController::class, 'showAdmin']);
    Route::put('/admin/clinic-treatments/{id}', [ConsultController::class, 'updateAdmin']);
    Route::delete('/admin/clinic-treatments/{id}', [ConsultController::class, 'destroyAdmin']);
    Route::put('/admin/clinic-notifications/consults/{id}/status', [ConsultController::class, 'updateConsultStatus']);
    Route::put('/admin/clinic-notifications/appointments/{id}/status', [ConsultController::class, 'updateAppointmentStatus']);

    Route::post('/reviews', [ReviewController::class, 'store']);

    // Rute Admin Review
    Route::get('/admin/reviews', [ReviewController::class, 'indexAdmin']);
    Route::delete('/admin/reviews/{id}', [ReviewController::class, 'destroyAdmin']);

    Route::get('/staff-list', [ChatController::class, 'getStaffList']);
    Route::get('/messages/{userId}', [ChatController::class, 'getMessages']);
    Route::post('/messages', [ChatController::class, 'sendMessage']);

    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);

    // Admin Routes (Sebaiknya di dalam middleware auth/admin)
    Route::post('/admin/events', [EventController::class, 'store']);
    Route::put('/admin/events/{id}', [EventController::class, 'update']);
    Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);
});

Route::middleware(['auth:sanctum'])->prefix('admin/dashboard')->group(function () {
    Route::get('/master-data', [DashboardController::class, 'getDashboardMasterData']);
    // Endpoint lama jika masih dipakai
    Route::get('/stats', [DashboardController::class, 'getStats']);
    Route::get('/revenue-chart', [DashboardController::class, 'getRevenueChart']);
    Route::get('/popular-products', [DashboardController::class, 'getPopularProducts']);
    Route::get('/predicted-bestsellers', [DashboardController::class, 'getPredictedBestsellers']);
    Route::get('/recent-activities', [DashboardController::class, 'getRecentActivities']);
    Route::get('/daily-average', [DashboardController::class, 'getAverageDailyRevenue']);
});

Route::get('/exchange-rates', function () {
    // Cek apakah data kurs sudah ada di Cache
    if (!Cache::has('exchange_rates')) {
        // Jika kosong (mungkin cron belum jalan), paksa jalankan command sekarang juga
        Artisan::call('currency:update-rates');
    }

    // Ambil data dari cache. Berikan nilai default IDR = 1 sebagai lapisan keamanan terakhir
    $rates = Cache::get('exchange_rates', ['IDR' => 1]);

    return response()->json([
        'status' => 'success',
        'base' => 'IDR',
        'data' => [
            'rates' => $rates,
            'last_updated' => now()->timezone('Asia/Jakarta')->toDateTimeString()
        ]
    ], 200);
});
