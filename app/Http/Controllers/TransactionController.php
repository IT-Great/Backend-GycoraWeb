<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\User;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoCode;
use Xendit\Configuration;
use App\Models\PromoClaim;
use App\Models\Transaction;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Xendit\Refund\RefundApi;
use App\Mail\RefundResultMail;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use App\Mail\LowStockAlertMail;
use Xendit\Refund\CreateRefund;
use App\Models\TransactionDetail;
use App\Services\BiteshipService;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendShippingUpdateJob;
use Illuminate\Support\Facades\Log;
use App\Services\PromoEngineService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Services\PointLedgerService;
use Illuminate\Support\Facades\Cache;
use App\Services\FraudDetectionService;
// 👇 [BARU] IMPORT POINT LEDGER SERVICE 👇
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;

        $latFrom = deg2rad((float) $lat1);
        $lonFrom = deg2rad((float) $lon1);
        $latTo = deg2rad((float) $lat2);
        $lonTo = deg2rad((float) $lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2)
            + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    // public function restoreProductStock($productId, $quantityToRestore)
    // {
    //     if ($quantityToRestore <= 0)
    //         return;

    //     // Tetap kembalikan riwayat batch stok (ProductStock) MESKIPUN produk utama sudah terhapus
    //     $remainingToRestore = $quantityToRestore;
    //     $incompleteBatches = ProductStock::where('product_id', $productId)
    //         ->whereColumn('quantity', '<', 'initial_quantity')
    //         ->orderBy('created_at', 'asc')
    //         ->lockForUpdate()
    //         ->get();

    //     foreach ($incompleteBatches as $batch) {
    //         if ($remainingToRestore <= 0)
    //             break;
    //         $spaceAvailable = $batch->initial_quantity - $batch->quantity;
    //         if ($spaceAvailable >= $remainingToRestore) {
    //             $batch->increment('quantity', $remainingToRestore);
    //             $remainingToRestore = 0;
    //         } else {
    //             $batch->increment('quantity', $spaceAvailable);
    //             $remainingToRestore -= $spaceAvailable;
    //         }
    //     }

    //     if ($remainingToRestore > 0) {
    //         $latestBatch = ProductStock::where('product_id', $productId)->orderBy('created_at', 'desc')->lockForUpdate()->first();
    //         if ($latestBatch) {
    //             $latestBatch->increment('quantity', $remainingToRestore);
    //             $latestBatch->increment('initial_quantity', $remainingToRestore);
    //         } else {
    //             ProductStock::create([
    //                 'product_id' => $productId,
    //                 'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
    //                 'quantity' => $remainingToRestore,
    //                 'initial_quantity' => $remainingToRestore,
    //             ]);
    //         }
    //     }

    //     // Kunci dan kembalikan stok produk utama hanya jika produknya masih eksis
    //     $product = Product::lockForUpdate()->find($productId);
    //     if ($product) {
    //         $product->increment('stock', $quantityToRestore);
    //     }
    // }

    public function restoreProductStock($productId, $quantityToRestore)
    {
        if ($quantityToRestore <= 0)
            return;

        // 👇 1. KUNCI INDUK DULU (PARENT LOCK) 👇
        $product = Product::lockForUpdate()->find($productId);

        if (!$product) {
            return; // Jika produk tidak ada, batalkan proses
        }

        // 👇 2. BARU KUNCI ANAKNYA (CHILD LOCK) 👇
        $remainingToRestore = $quantityToRestore;
        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($incompleteBatches as $batch) {
            if ($remainingToRestore <= 0)
                break;
            $spaceAvailable = $batch->initial_quantity - $batch->quantity;
            if ($spaceAvailable >= $remainingToRestore) {
                $batch->increment('quantity', $remainingToRestore);
                $remainingToRestore = 0;
            } else {
                $batch->increment('quantity', $spaceAvailable);
                $remainingToRestore -= $spaceAvailable;
            }
        }

        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id', $productId)->orderBy('created_at', 'desc')->lockForUpdate()->first();
            if ($latestBatch) {
                $latestBatch->increment('quantity', $remainingToRestore);
                $latestBatch->increment('initial_quantity', $remainingToRestore);
            } else {
                ProductStock::create([
                    'product_id' => $productId,
                    'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore,
                ]);
            }
        }

        // 3. Kembalikan stok produk utama
        $product->increment('stock', $quantityToRestore);
    }

    // public function checkout(Request $request)
    // {
    //     $user = $request->user();
    //     if (!$user) {
    //         return response()->json(['message' => 'Sesi kedaluwarsa. Silakan login kembali.'], 401);
    //     }

    //     $request->validate([
    //         'address_id' => 'required',
    //         'shipping_method' => 'required|in:free,biteship',
    //         'use_points' => 'nullable|integer|min:0',
    //         'cart_ids' => 'required|array',
    //         'cart_ids.*' => 'exists:carts,id',
    //         'shipping_cost' => 'nullable|numeric',
    //         'courier_company' => 'nullable|string',
    //         'courier_type' => 'nullable|string',
    //         'delivery_type' => 'nullable|string',
    //         'ab_test_variant' => 'nullable|string|in:A,B',
    //     ]);

    //     $ticketId = 'TCK-' . $user->id . '-' . Str::random(8);
    //     $position = rand(150, 350);

    //     Cache::put("checkout_ticket:{$ticketId}", json_encode([
    //         'status' => 'waiting',
    //         'position' => $position,
    //         'message' => 'Menunggu giliran server...'
    //     ]), 900);

    //     \App\Jobs\ProcessFlashSaleCheckout::dispatch(
    //         $user->id,
    //         $request->all(),
    //         $request->ip(),
    //         $ticketId
    //     );

    //     return response()->json([
    //         'ticket_id' => $ticketId,
    //         'position' => $position,
    //         'status' => 'waiting'
    //     ]);
    // }

    public function checkout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Sesi kedaluwarsa. Silakan login kembali.'], 401);
        }

        $request->validate([
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'use_points' => 'nullable|integer|min:0',
            'cart_ids' => 'required|array',
            'cart_ids.*' => 'exists:carts,id',
            'shipping_cost' => 'nullable|numeric',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'delivery_type' => 'nullable|string',
            'ab_test_variant' => 'nullable|string|in:A,B',
        ]);

        try {
            // Mencegah Deadlock: Urutkan item keranjang berdasarkan product_id sebelum di-loop
            $cartItems = Cart::with('product')
                ->where('user_id', $user->id)
                ->whereIn('id', $request->cart_ids)
                ->get()
                ->sortBy('product_id');

            if ($cartItems->isEmpty()) {
                return response()->json(['message' => 'Keranjang kosong saat diproses.'], 400);
            }

            $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
                $lockedUser = User::lockForUpdate()->find($user->id);

                $promoType = $request->promo_type ?? null;
                $inputCode = !empty($request->promo_code) ? strtoupper($request->promo_code) : null;
                $appliedPromoCode = null;
                $isClaimPromo = false;

                if ($inputCode && $promoType === 'claim') {
                    $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $inputCode)->lockForUpdate()->first();
                    if (!$promoClaim || $promoClaim->is_used)
                        throw new \Exception('Promo tidak valid.');
                    if ($promoClaim->expires_at && Carbon::now()->greaterThan($promoClaim->expires_at))
                        throw new \Exception('Promo kedaluwarsa.');
                    $appliedPromoCode = $promoClaim->promo_code;
                    $promoClaim->update(['is_used' => true, 'used_at' => now()]);
                    $isClaimPromo = true;
                } elseif ($inputCode && $promoType === 'voucher') {
                    $voucher = PromoCode::where('code', $inputCode)->lockForUpdate()->first();
                    if (!$voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses)
                        throw new \Exception('Voucher habis.');
                    $appliedPromoCode = $voucher->code;
                    $voucher->increment('times_used');
                }

                $totalAmount = 0;
                $itemTotals = [];
                $driversPool = [];
                $partnersPool = [];
                $hasBundleProduct = false;

                $totalCartQty = $cartItems->sum('quantity');
                $isWholesaleGlobal = $lockedUser->usertype === 'reseller' && $totalCartQty >= 24;

                $lockedProductsByCartId = [];

                foreach ($cartItems as $item) {
                    $product = Product::with('category')->lockForUpdate()->find($item->product_id);
                    $lockedProductsByCartId[$item->id] = $product;
                    if (!$product || $product->stock < $item->quantity)
                        throw new \Exception('Stok produk ' . ($product ? $product->name : 'dihapus') . ' telah habis.');

                    $normalPrice = $product->price;
                    if ($isWholesaleGlobal && $product->wholesale_price > 0)
                        $normalPrice = $product->wholesale_price;
                    elseif ($product->discount_price > 0 && $product->discount_price < $product->price)
                        $normalPrice = $product->discount_price;

                    if ($promoType === 'voucher' && $product->voucher_discount_price > 0)
                        $normalPrice = $product->voucher_discount_price;

                    $itemTotals[$item->id] = 0;

                    if ($isWholesaleGlobal && $product->wholesale_price > 0) {
                        $itemTotals[$item->id] = $normalPrice * $item->quantity;
                        continue;
                    }

                    $sku = strtoupper($product->sku ?? '');
                    $isEGB = str_starts_with($sku, 'EGB');
                    $isBundleValid = filter_var($product->is_bundle_active, FILTER_VALIDATE_BOOLEAN) || ($product->category && $product->category->code === 'BN-01');

                    if ($isBundleValid)
                        $hasBundleProduct = true;

                    $isValidDate = true;
                    if (!empty($product->bundle_end_date) && $product->bundle_end_date !== '0000-00-00 00:00:00') {
                        try {
                            $isValidDate = Carbon::parse($product->bundle_end_date)->isFuture();
                        } catch (\Exception $e) {
                            $isValidDate = false;
                        }
                    }

                    $isDriver = $isEGB && $isBundleValid && $isValidDate && $product->bundle_price > 0;

                    for ($i = 0; $i < $item->quantity; $i++) {
                        $poolItem = ['cart_id' => $item->id, 'normal_price' => $normalPrice, 'bundle_price' => $product->bundle_price ?? 0];
                        if ($isDriver)
                            $driversPool[] = $poolItem;
                        elseif (!$isEGB)
                            $partnersPool[] = $poolItem;
                        else
                            $itemTotals[$item->id] += $normalPrice;
                    }
                }

                if (count($driversPool) > 0 && count($partnersPool) > 0) {
                    usort($driversPool, function ($a, $b) {
                        return $b['bundle_price'] <=> $a['bundle_price'];
                    });
                    while (count($driversPool) > 0 && count($partnersPool) > 0) {
                        $driver = array_shift($driversPool);
                        $partner = array_shift($partnersPool);
                        $discountForPair = ($driver['normal_price'] + $partner['normal_price']) - $driver['bundle_price'];

                        if ($discountForPair > 0) {
                            $driverProdModel = $lockedProductsByCartId[$driver['cart_id']];
                            if ($driverProdModel->has_bundle_freebie && $driverProdModel->bundle_freebie_quota > 0) {
                                $driverProdModel->bundle_freebie_quota -= 1;
                                $driverProdModel->save();
                            }
                            $halfPrice = floor($driver['bundle_price'] / 2);
                            $remainder = $driver['bundle_price'] % 2;

                            $itemTotals[$driver['cart_id']] += ($halfPrice + $remainder);
                            $itemTotals[$partner['cart_id']] += $halfPrice;
                        } else {
                            $itemTotals[$driver['cart_id']] += $driver['normal_price'];
                            $itemTotals[$partner['cart_id']] += $partner['normal_price'];
                        }
                    }
                }

                foreach ($driversPool as $driver)
                    $itemTotals[$driver['cart_id']] += $driver['normal_price'];
                foreach ($partnersPool as $partner)
                    $itemTotals[$partner['cart_id']] += $partner['normal_price'];

                $totalAmount = (int) array_sum($itemTotals);

                $promoEngine = new PromoEngineService;
                $dynamicPromoResult = $promoEngine->calculate($totalAmount, $hasBundleProduct);
                $merdekaDiscount = $dynamicPromoResult['discount_amount'];
                if ($dynamicPromoResult['promo_tag'])
                    $appliedPromoCode = $appliedPromoCode ? $appliedPromoCode . ' + ' . $dynamicPromoResult['promo_tag'] : $dynamicPromoResult['promo_tag'];

                $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

                $promoDiscountAmount = 0;
                if ($isClaimPromo) {
                    if ($totalAmount < 50000)
                        throw new \Exception('Minimum belanja Rp 50.000');
                    $promoDiscountAmount = floor($totalAmount * 0.1) + min(10000, $totalShippingCost);
                }
                $promoDiscountAmount += $merdekaDiscount;

                $totalAfterPromo = (int) max(0, ($totalAmount + $totalShippingCost) - $promoDiscountAmount);
                $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
                $pointsUsed = 0;

                if ($request->use_points > 0 && $lockedUser->is_membership) {
                    $pointsUsed = floor(min($request->use_points * 1000, $totalAfterPromo) / 1000);
                }

                $address = \App\Models\Address::find($request->address_id);
                $buyerLat = $address && $address->latitude ? (float) $address->latitude : -6.2088;
                $buyerLon = $address && $address->longitude ? (float) $address->longitude : 106.8456;

                $warehouses = [
                    ['code' => 'WH-JKT', 'name' => 'Jakarta Central', 'lat' => -6.2088, 'lon' => 106.8456],
                    ['code' => 'WH-SUB', 'name' => 'Surabaya Hub', 'lat' => -7.2504, 'lon' => 112.7688],
                    ['code' => 'WH-DPS', 'name' => 'Bali Fulfillment', 'lat' => -8.4095, 'lon' => 115.1889],
                ];

                foreach ($warehouses as &$wh) {
                    $wh['distance'] = $this->calculateHaversineDistance($buyerLat, $buyerLon, $wh['lat'], $wh['lon']);
                }

                usort($warehouses, function ($a, $b) {
                    return $a['distance'] <=> $b['distance'];
                });

                $fulfillmentLog = [];
                $receiverName = $address ? $address->first_name_address . ' ' . $address->last_name_address : 'Unknown';
                $fraudAnalysis = app(FraudDetectionService::class)->analyze($lockedUser, $request->ip(), $receiverName, $totalAmount, $address ? $address->city : 'Unknown');

                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'address_id' => $request->address_id,
                    'shipping_method' => $request->shipping_method,
                    'shipping_cost' => $totalShippingCost,
                    'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
                    'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
                    'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                    'order_id' => $orderId,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                    'point' => $earnedPoints,
                    'points_used' => $pointsUsed,
                    'promo_code' => $appliedPromoCode,
                    'promo_discount' => $promoDiscountAmount,
                    'fraud_score' => $fraudAnalysis['score'],
                    'fraud_flags' => $fraudAnalysis['flags'],
                    'ab_test_variant' => $request->ab_test_variant ?? 'A',
                ]);

                if ($pointsUsed > 0) {
                    PointLedgerService::deductPoints(
                        $lockedUser->id,
                        $pointsUsed,
                        'checkout_usage',
                        "Penggunaan poin untuk Order ID: {$orderId}",
                        $transaction->id
                    );
                }

                foreach ($cartItems as $item) {
                    $product = Product::find($item->product_id);
                    $calculatedGross = $itemTotals[$item->id] ?? 0;

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $item->quantity > 0 ? floor($calculatedGross / $item->quantity) : 0,
                        'color' => $item->color,
                    ]);

                    $product->decrement('stock', $item->quantity);

                    if ($product->stock <= 5) {
                        try {
                            Mail::to('gycora.essence@gmail.com')->queue(new LowStockAlertMail($product));
                        } catch (\Exception $e) {}
                    }
                }

                Cart::where('user_id', $lockedUser->id)->whereIn('id', $request->cart_ids)->delete();
                $this->sendMetaConversionApiEvent($transaction, $lockedUser, $cartItems, $totalAmount, $itemTotals);

                return $transaction;
            });

            // Panggil PaymentController secara langsung dan sinkron
            $paymentController = app(PaymentController::class);
            $invoiceRequest = new Request([
                'transaction_id' => $transactionData->id,
                'currency' => 'IDR',
                'user_id' => $user->id,
            ]);

            $invoiceRes = $paymentController->createInvoice($invoiceRequest);
            $invoiceData = $invoiceRes->getData(true);

            return response()->json([
                'status' => 'success',
                'checkout_url' => $invoiceData['checkout_url']
            ]);

        } catch (\Exception $e) {
            Log::error('Direct Checkout Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function checkTicketStatus($ticketId)
    {
        $data = Cache::get("checkout_ticket:{$ticketId}");
        if (!$data) {
            return response()->json(['status' => 'error', 'message' => 'Sesi antrean kedaluwarsa atau hilang.'], 404);
        }
        $ticketData = json_decode($data, true);

        if ($ticketData['status'] === 'waiting' && $ticketData['position'] > 1) {
            $ticketData['position'] = max(1, $ticketData['position'] - rand(3, 12));
            Cache::put("checkout_ticket:{$ticketId}", json_encode($ticketData), 900);
        }
        return response()->json($ticketData);
    }

    public function executeCheckoutLogic($userId, $requestData, $ipAddress, $ticketId)
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception('Sesi pengguna tidak valid atau tidak ditemukan.');
            }

            \Illuminate\Support\Facades\Auth::loginUsingId($userId);

            $request = \Illuminate\Http\Request::create('/api/checkout', 'POST', $requestData);
            $request->server->set('REMOTE_ADDR', $ipAddress);

            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            app()->instance('request', $request);

            // Mencegah Deadlock: Urutkan item keranjang berdasarkan product_id sebelum di-loop
            $cartItems = Cart::with('product')
                ->where('user_id', $user->id)
                ->whereIn('id', $request->cart_ids)
                ->get()
                ->sortBy('product_id');

            if ($cartItems->isEmpty()) {
                throw new \Exception('Keranjang kosong saat diproses.');
            }

            $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
                $lockedUser = User::lockForUpdate()->find($user->id);

                $promoType = $request->promo_type ?? null;
                $inputCode = !empty($request->promo_code) ? strtoupper($request->promo_code) : null;
                $appliedPromoCode = null;
                $isClaimPromo = false;

                if ($inputCode && $promoType === 'claim') {
                    $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $inputCode)->lockForUpdate()->first();
                    if (!$promoClaim || $promoClaim->is_used)
                        throw new \Exception('Promo tidak valid.');
                    if ($promoClaim->expires_at && Carbon::now()->greaterThan($promoClaim->expires_at))
                        throw new \Exception('Promo kedaluwarsa.');
                    $appliedPromoCode = $promoClaim->promo_code;
                    $promoClaim->update(['is_used' => true, 'used_at' => now()]);
                    $isClaimPromo = true;
                } elseif ($inputCode && $promoType === 'voucher') {
                    $voucher = PromoCode::where('code', $inputCode)->lockForUpdate()->first();
                    if (!$voucher || ($voucher->expires_at && now()->greaterThan($voucher->expires_at)) || $voucher->times_used >= $voucher->max_uses)
                        throw new \Exception('Voucher habis.');
                    $appliedPromoCode = $voucher->code;
                    $voucher->increment('times_used');
                }

                $totalAmount = 0;
                $itemTotals = [];
                $driversPool = [];
                $partnersPool = [];
                $hasBundleProduct = false;

                $totalCartQty = $cartItems->sum('quantity');
                $isWholesaleGlobal = $lockedUser->usertype === 'reseller' && $totalCartQty >= 24;

                $lockedProductsByCartId = [];

                foreach ($cartItems as $item) {
                    $product = Product::with('category')->lockForUpdate()->find($item->product_id);
                    $lockedProductsByCartId[$item->id] = $product;
                    if (!$product || $product->stock < $item->quantity)
                        throw new \Exception('Stok produk ' . ($product ? $product->name : 'dihapus') . ' telah habis.');

                    $normalPrice = $product->price;
                    if ($isWholesaleGlobal && $product->wholesale_price > 0)
                        $normalPrice = $product->wholesale_price;
                    elseif ($product->discount_price > 0 && $product->discount_price < $product->price)
                        $normalPrice = $product->discount_price;

                    if ($promoType === 'voucher' && $product->voucher_discount_price > 0)
                        $normalPrice = $product->voucher_discount_price;

                    $itemTotals[$item->id] = 0;

                    if ($isWholesaleGlobal && $product->wholesale_price > 0) {
                        $itemTotals[$item->id] = $normalPrice * $item->quantity;
                        continue;
                    }

                    $sku = strtoupper($product->sku ?? '');
                    $isEGB = str_starts_with($sku, 'EGB');
                    $isBundleValid = filter_var($product->is_bundle_active, FILTER_VALIDATE_BOOLEAN) || ($product->category && $product->category->code === 'BN-01');

                    if ($isBundleValid)
                        $hasBundleProduct = true;

                    $isValidDate = true;
                    if (!empty($product->bundle_end_date) && $product->bundle_end_date !== '0000-00-00 00:00:00') {
                        try {
                            $isValidDate = Carbon::parse($product->bundle_end_date)->isFuture();
                        } catch (\Exception $e) {
                            $isValidDate = false;
                        }
                    }

                    $isDriver = $isEGB && $isBundleValid && $isValidDate && $product->bundle_price > 0;

                    for ($i = 0; $i < $item->quantity; $i++) {
                        $poolItem = ['cart_id' => $item->id, 'normal_price' => $normalPrice, 'bundle_price' => $product->bundle_price ?? 0];
                        if ($isDriver)
                            $driversPool[] = $poolItem;
                        elseif (!$isEGB)
                            $partnersPool[] = $poolItem;
                        else
                            $itemTotals[$item->id] += $normalPrice;
                    }
                }

                if (count($driversPool) > 0 && count($partnersPool) > 0) {
                    usort($driversPool, function ($a, $b) {
                        return $b['bundle_price'] <=> $a['bundle_price'];
                    });
                    while (count($driversPool) > 0 && count($partnersPool) > 0) {
                        $driver = array_shift($driversPool);
                        $partner = array_shift($partnersPool);
                        $discountForPair = ($driver['normal_price'] + $partner['normal_price']) - $driver['bundle_price'];

                        if ($discountForPair > 0) {
                            $driverProdModel = $lockedProductsByCartId[$driver['cart_id']];
                            if ($driverProdModel->has_bundle_freebie && $driverProdModel->bundle_freebie_quota > 0) {
                                $driverProdModel->bundle_freebie_quota -= 1;
                                $driverProdModel->save();
                            }
                            $halfPrice = floor($driver['bundle_price'] / 2);
                            $remainder = $driver['bundle_price'] % 2;

                            $itemTotals[$driver['cart_id']] += ($halfPrice + $remainder);
                            $itemTotals[$partner['cart_id']] += $halfPrice;
                        } else {
                            $itemTotals[$driver['cart_id']] += $driver['normal_price'];
                            $itemTotals[$partner['cart_id']] += $partner['normal_price'];
                        }
                    }
                }

                foreach ($driversPool as $driver)
                    $itemTotals[$driver['cart_id']] += $driver['normal_price'];
                foreach ($partnersPool as $partner)
                    $itemTotals[$partner['cart_id']] += $partner['normal_price'];

                $totalAmount = (int) array_sum($itemTotals);

                $promoEngine = new PromoEngineService;
                $dynamicPromoResult = $promoEngine->calculate($totalAmount, $hasBundleProduct);
                $merdekaDiscount = $dynamicPromoResult['discount_amount'];
                if ($dynamicPromoResult['promo_tag'])
                    $appliedPromoCode = $appliedPromoCode ? $appliedPromoCode . ' + ' . $dynamicPromoResult['promo_tag'] : $dynamicPromoResult['promo_tag'];

                $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

                $promoDiscountAmount = 0;
                if ($isClaimPromo) {
                    if ($totalAmount < 50000)
                        throw new \Exception('Minimum belanja Rp 50.000');
                    $promoDiscountAmount = floor($totalAmount * 0.1) + min(10000, $totalShippingCost);
                }
                $promoDiscountAmount += $merdekaDiscount;

                $totalAfterPromo = (int) max(0, ($totalAmount + $totalShippingCost) - $promoDiscountAmount);
                $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
                $pointsUsed = 0;

                // Hitung poin yang digunakan, JANGAN kurangi dulu
                if ($request->use_points > 0 && $lockedUser->is_membership) {
                    $pointsUsed = floor(min($request->use_points * 1000, $totalAfterPromo) / 1000);
                }

                $address = \App\Models\Address::find($request->address_id);
                $buyerLat = $address && $address->latitude ? (float) $address->latitude : -6.2088;
                $buyerLon = $address && $address->longitude ? (float) $address->longitude : 106.8456;

                $warehouses = [
                    ['code' => 'WH-JKT', 'name' => 'Jakarta Central', 'lat' => -6.2088, 'lon' => 106.8456],
                    ['code' => 'WH-SUB', 'name' => 'Surabaya Hub', 'lat' => -7.2504, 'lon' => 112.7688],
                    ['code' => 'WH-DPS', 'name' => 'Bali Fulfillment', 'lat' => -8.4095, 'lon' => 115.1889],
                ];

                foreach ($warehouses as &$wh) {
                    $wh['distance'] = $this->calculateHaversineDistance($buyerLat, $buyerLon, $wh['lat'], $wh['lon']);
                }

                usort($warehouses, function ($a, $b) {
                    return $a['distance'] <=> $b['distance'];
                });

                $fulfillmentLog = [];

                $receiverName = $address ? $address->first_name_address . ' ' . $address->last_name_address : 'Unknown';
                $fraudAnalysis = app(FraudDetectionService::class)->analyze($lockedUser, $request->ip(), $receiverName, $totalAmount, $address ? $address->city : 'Unknown');

                $finalStatus = 'pending';

                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'address_id' => $request->address_id,
                    'shipping_method' => $request->shipping_method,
                    'shipping_cost' => $totalShippingCost,
                    'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
                    'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
                    'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                    'order_id' => $orderId,
                    'total_amount' => $totalAmount,
                    'status' => $finalStatus,
                    'point' => $earnedPoints,
                    'points_used' => $pointsUsed,
                    'promo_code' => $appliedPromoCode,
                    'promo_discount' => $promoDiscountAmount,
                    'fraud_score' => $fraudAnalysis['score'],
                    'fraud_flags' => $fraudAnalysis['flags'],
                    'ab_test_variant' => $request->ab_test_variant ?? 'A',
                ]);

                // 👇 [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE 👇
                if ($pointsUsed > 0) {
                    PointLedgerService::deductPoints(
                        $lockedUser->id,
                        $pointsUsed,
                        'checkout_usage',
                        "Penggunaan poin untuk Order ID: {$orderId}",
                        $transaction->id
                    );
                }

                foreach ($cartItems as $item) {
                    $product = Product::find($item->product_id);
                    $calculatedGross = $itemTotals[$item->id] ?? 0;

                    $qtyNeeded = $item->quantity;
                    $itemRoutes = [];

                    foreach ($warehouses as $wh) {
                        if ($qtyNeeded <= 0)
                            break;

                        $dummyAvailableInWh = ceil($product->stock * 0.7);

                        if ($dummyAvailableInWh > 0) {
                            $takeQty = min($qtyNeeded, $dummyAvailableInWh);
                            $itemRoutes[] = [
                                'wh_code' => $wh['code'],
                                'qty_taken' => $takeQty,
                                'distance_km' => round($wh['distance'], 2)
                            ];
                            $qtyNeeded -= $takeQty;
                        }
                    }

                    $fulfillmentLog[$product->sku] = $itemRoutes;

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $item->quantity > 0 ? floor($calculatedGross / $item->quantity) : 0,
                        'color' => $item->color,
                    ]);

                    $product->decrement('stock', $item->quantity);

                    if ($product->stock <= 5) {
                        try {
                            Mail::to('gycora.essence@gmail.com')->queue(new LowStockAlertMail($product));
                        } catch (\Exception $e) {
                        }
                    }
                }

                Log::info("Order {$orderId} Geo-Routing Plan:", $fulfillmentLog);

                Cart::where('user_id', $lockedUser->id)->whereIn('id', $request->cart_ids)->delete();

                $this->sendMetaConversionApiEvent($transaction, $lockedUser, $cartItems, $totalAmount, $itemTotals);

                return ['transaction' => $transaction];
            });

            $transactionController = app(PaymentController::class);

            $request->merge([
                'transaction_id' => $transactionData['transaction']->id,
                'currency' => 'IDR',
                'user_id' => $user->id,
            ]);

            $invoiceRes = $transactionController->createInvoice($request);
            $invoiceData = $invoiceRes->getData(true);

            Cache::put("checkout_ticket:{$ticketId}", json_encode([
                'status' => 'success',
                'checkout_url' => $invoiceData['checkout_url']
            ]), 900);
        } catch (\Exception $e) {
            Log::error('Flash Sale Checkout Error: ' . $e->getMessage());
            Cache::put("checkout_ticket:{$ticketId}", json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]), 900);
        }
    }

    public function index(Request $request)
    {
        $transactions = Transaction::with(['details.product', 'payment', 'address'])->where('user_id', $request->user()->id)->latest()->get();
        return response()->json($transactions);
    }

    public function allTransactions()
    {
        $transactions = Transaction::with(['user', 'details.product', 'address'])->latest()->get();
        return response()->json($transactions);
    }

    public function cancelOrder(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);
        if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing', 'on_hold'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }
        if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);
                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');
                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        return response()->json(['message' => 'Cannot cancel: The package is already being processed by the courier.'], 400);
                    }
                    Http::withHeaders(['Authorization' => config('services.biteship.api_key')])->delete('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);
                }
            } catch (\Exception $e) {
            }

            try {
                $transaction->load('payment');
                if ($transaction->payment && $transaction->payment->external_id) {
                    $invoiceApi = new InvoiceApi;
                    $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);
                    if (!empty($invoices) && count($invoices) > 0) {
                        $xenditInvoiceId = $invoices[0]['id'];
                        $refundApi = new RefundApi;
                        $refundRequest = new CreateRefund([
                            'invoice_id' => $xenditInvoiceId,
                            'reason' => 'REQUESTED_BY_CUSTOMER',
                            'amount' => (int) $transaction->total_amount,
                            'metadata' => ['order_id' => $transaction->order_id],
                        ]);
                        $refundApi->createRefund(null, null, $refundRequest);
                    }
                }
            } catch (\Exception $e) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
            }
        }

        DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);
            if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
                $lockedTransaction->update(['status' => 'cancelled', 'shipping_status' => 'cancelled']);

                // 👇 [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE 👇
                if ($lockedTransaction->points_used > 0) {
                    PointLedgerService::addPoints(
                        $lockedTransaction->user_id,
                        $lockedTransaction->points_used,
                        'refund',
                        "Pengembalian poin dari pembatalan pesanan: {$lockedTransaction->order_id}",
                        $lockedTransaction->id
                    );
                }

                if ($lockedTransaction->promo_code) {
                    PromoClaim::where('email', $lockedTransaction->user->email)->where('promo_code', $lockedTransaction->promo_code)->update(['is_used' => false, 'used_at' => null]);
                }
                if ($lockedTransaction->payment) {
                    $lockedTransaction->payment->update(['status' => 'EXPIRED']);
                }

                $sortedDetails = $lockedTransaction->details->sortBy('product_id');
                foreach ($sortedDetails as $detail) {
                    $this->restoreProductStock($detail->product_id, $detail->quantity);
                }
            }
        });
        Cache::flush();

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);
        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }
        $transaction->update(['status' => 'completed']);
        $transaction->user->refresh();

        // 👇 [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE 👇
        if ($transaction->point > 0 && $transaction->user->is_membership) {
            PointLedgerService::addPoints(
                $transaction->user_id,
                $transaction->point,
                'order_reward',
                "Cashback poin dari pesanan selesai (Manual): {$transaction->order_id}",
                $transaction->id
            );
        }

        return response()->json(['message' => 'Order completed!']);
    }

    public function requestRefund(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        $request->validate([
            'reason' => 'required|string|max:1000',
            'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240',
        ]);

        try {
            $file = $request->file('proof_file');
            $path = $file->store('refund_proofs', [
                'disk' => 's3',
                'visibility' => 'public',
            ]);
            $proofUrl = Storage::disk('s3')->url($path);

            $transaction->update([
                'status' => 'refund_requested',
                'refund_reason' => $request->reason,
                'refund_proof_url' => $proofUrl,
            ]);

            return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);
        } catch (\Exception $e) {
            Log::error('Failed to upload refund proof: ' . $e->getMessage());

            return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
        }
    }

    public function processRefundUser(Request $request, $id)
    {
        $transaction = Transaction::with('payment')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $locked = Transaction::where('id', $id)
            ->where('status', 'refund_approved')
            ->update(['status' => 'refund_processing']);

        if (!$locked) {
            return response()->json(['message' => 'Refund is already being processed or not valid.'], 400);
        }

        if (!$transaction->payment) {
            $transaction->update(['status' => 'refund_approved']);
            return response()->json(['message' => 'Payment data not found.'], 404);
        }

        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        $transaction->update(['status' => 'refund_approved']);

                        return response()->json([
                            'message' => 'Cannot process refund: The package is already in transit or has issues. Please contact logistics.',
                        ], 400);
                    }

                    if (!in_array($biteshipStatus, ['cancelled'])) {
                        $cancelRes = Http::withHeaders([
                            'Authorization' => config('services.biteship.api_key'),
                        ])->delete('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                        $cancelData = $cancelRes->json();
                        if (isset($cancelData['success']) && $cancelData['success'] === false) {
                            $transaction->update(['status' => 'refund_approved']);

                            return response()->json([
                                'message' => 'Failed to cancel courier. Refund aborted to prevent loss.',
                            ], 400);
                        }
                    }
                }
            } catch (\Exception $e) {
                $transaction->update(['status' => 'refund_approved']);
                Log::error('Biteship Pre-Check Error: ' . $e->getMessage());

                return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
            }
        }

        try {
            $invoiceApi = new InvoiceApi;
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception('Invoice not found in Xendit.');
            }

            $xenditInvoiceId = $invoices[0]['id'];
            $refundApi = new RefundApi;

            $refundRequest = new CreateRefund([
                'invoice_id' => $xenditInvoiceId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => ['order_id' => $transaction->order_id],
            ]);

            $refundApi->createRefund(null, null, $refundRequest);

            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }

                $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned'];
                $originalStatus = $transaction->getOriginal('status');

                if (!in_array($originalStatus, $statusesThatAlreadyRestoredStock)) {
                    $sortedDetails = $transaction->details->sortBy('product_id');
                    foreach ($sortedDetails as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            });

            Cache::flush();

            return response()->json([
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic',
            ]);
        } catch (XenditSdkException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    // 👇 TAMBAHKAN SORTING ANTI-DEADLOCK 👇
                    $sortedDetails = $transaction->details->sortBy('product_id');
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                Cache::flush();

                return response()->json([
                    'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
                    'code' => 'MANUAL_REFUND_NEEDED',
                ], 200);
            }

            $transaction->update(['status' => 'refund_approved']);

            return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
        } catch (\Exception $e) {
            $transaction->update(['status' => 'refund_approved']);

            return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
        }
    }

    public function approveRefund($id)
    {
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_approved']);

        try {
            Mail::to($transaction->user->email)->queue(new RefundResultMail($transaction, 'approve'));
        } catch (\Exception $e) {
            Log::error("Gagal kirim email Approve Refund ke {$transaction->user->email}: " . $e->getMessage());
        }

        return response()->json(['message' => 'Refund request approved. Email sent to customer.']);
    }

    public function rejectRefund($id)
    {
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_rejected']);

        try {
            Mail::to($transaction->user->email)->queue(new RefundResultMail($transaction, 'reject'));
        } catch (\Exception $e) {
            Log::error("Gagal kirim email Reject Refund ke {$transaction->user->email}: " . $e->getMessage());
        }

        return response()->json(['message' => 'Refund request rejected. Email sent to customer.']);
    }

    public function show($id)
    {
        return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
    }

    public function adminShow($id)
    {
        $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function salesReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $search = $request->query('search');

        $query = TransactionDetail::query()
            ->select(
                'products.id',
                'products.sku',
                'products.name',
                'products.image_url',
                'categories.name as category_name',
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('transactions.status', ['completed', 'refund_rejected']);

        if ($month && $year) {
            $query
                ->whereMonth('transactions.created_at', $month)
                ->whereYear('transactions.created_at', $year);
        } elseif ($year) {
            $query->whereYear('transactions.created_at', $year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q
                    ->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%");
            });
        }

        $report = $query
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.image_url', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report,
        ]);
    }

    public function trackOrder($id)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function bulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        $transactions = Transaction::where('user_id', $request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        foreach ($transactions as $transaction) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];
                }
            } catch (\Exception $e) {
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        return response()->json($trackingData);
    }

    public function adminBulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        $transactions = Transaction::whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        foreach ($transactions as $transaction) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];
                }
            } catch (\Exception $e) {
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        return response()->json($trackingData);
    }

    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id);

        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        if (!$transaction->biteship_order_id) {
            return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
        }

        $queryString = http_build_query($request->all());

        $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get($biteshipUrl);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
            }

            return response()->json(['message' => 'Gagal mengambil resi dari Biteship: ' . $response->body()], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
        }
    }

    public function retryShipping($id)
    {
        $transaction = Transaction::with(['details.product', 'address', 'user'])->findOrFail($id);

        if ($transaction->shipping_method !== 'biteship') {
            return response()->json(['message' => 'Metode pengiriman bukan Biteship, tidak bisa di-request ulang.'], 400);
        }

        if ($transaction->biteship_order_id && !str_starts_with($transaction->tracking_number, 'API ERR') && !str_starts_with($transaction->tracking_number, 'SYS ERR')) {
            return response()->json(['message' => 'Pesanan ini sudah memiliki nomor resi yang valid.'], 400);
        }

        try {
            $biteship = new BiteshipService;
            $order = $biteship->createOrder($transaction);

            if (isset($order['id'])) {
                $transaction->update([
                    'biteship_order_id' => $order['id'],
                    'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
                    'shipping_status' => strtolower($order['status'] ?? 'pending'),
                ]);

                return response()->json([
                    'message' => 'Berhasil! Pesanan sukses dikirim ulang ke Biteship.',
                    'tracking_number' => $transaction->tracking_number,
                ]);
            } else {
                $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');

                $transaction->update([
                    'tracking_number' => 'API ERR: ' . substr($errorMsg, 0, 200),
                    'shipping_status' => 'error',
                ]);

                Log::error('Biteship Retry Failed: ' . json_encode($order));

                return response()->json(['message' => 'Gagal request ke Biteship: ' . $errorMsg], 400);
            }
        } catch (\Exception $e) {
            $transaction->update([
                'tracking_number' => 'SYS ERR: ' . substr($e->getMessage(), 0, 200),
                'shipping_status' => 'error',
            ]);

            return response()->json(['message' => 'Terjadi kesalahan sistem internal: ' . $e->getMessage()], 500);
        }
    }

    public function biteshipCallback(Request $request)
    {
        $biteshipOrderId = $request->input('order_id');
        $status = strtolower($request->input('status'));
        $waybill = $request->input('courier_waybill_id');

        \Log::info('Biteship Webhook Received: ', $request->all());

        return DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {
            $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                return response()->json(['message' => 'Transaction not found'], 200);
            }

            if ($transaction->status === 'completed' && $status === 'delivered') {
                return response()->json(['message' => 'Already completed'], 200);
            }

            $updates = ['shipping_status' => $status];

            if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
                $updates['tracking_number'] = $waybill;
            }

            if ($status === 'delivered' && $transaction->status === 'processing') {
                $updates['status'] = 'completed';

                $transaction->update($updates);

                $this->checkAndAssignMembership($transaction->user);

                $transaction->user->refresh();

                // 👇 [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE 👇
                if ($transaction->point > 0 && $transaction->user->is_membership) {
                    PointLedgerService::addPoints(
                        $transaction->user_id,
                        $transaction->point,
                        'order_reward',
                        "Cashback poin dari pesanan pengiriman sukses: {$transaction->order_id}",
                        $transaction->id
                    );
                }

                return response()->json(['message' => 'Webhook processed and membership checked']);
            }

            if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
                $updates['status'] = 'refund_manual_required';
                $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
                \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
            }

            if ($status === 'disposed' && $transaction->status === 'processing') {
                $updates['status'] = 'shipping_failed';
                $updates['tracking_number'] = 'Shipping Failed';
                \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
            }

            if ($status === 'returned' && $transaction->status === 'processing') {
                $updates['status'] = 'returned';
                $updates['tracking_number'] = 'Shipping Returned';
                \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
            }

            $transaction->update($updates);

            SendShippingUpdateJob::dispatch($transaction->id, $status);

            return response()->json(['message' => 'Webhook processed successfully']);
        });
    }

    private function checkAndAssignMembership($user)
    {
        if ($user->is_membership) {
            return;
        }

        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount');

        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }

    private function sendMetaConversionApiEvent($transaction, $user, $cartItems, $totalAmount, $itemTotals)
    {
        try {
            $pixelId = env('META_PIXEL_ID');
            $accessToken = env('META_CAPI_ACCESS_TOKEN');

            if (!$pixelId || !$accessToken) {
                return;
            }

            $hashedEmail = hash('sha256', strtolower(trim($user->email)));
            $hashedPhone = $user->phone ? hash('sha256', preg_replace('/[^0-9]/', '', $user->phone)) : null;

            $userData = ['em' => $hashedEmail];
            if ($hashedPhone) {
                $userData['ph'] = $hashedPhone;
            }

            $contents = [];
            foreach ($cartItems as $item) {
                $product = $item->product;
                if ($product) {
                    $itemTotal = $itemTotals[$item->id] ?? ($product->price * $item->quantity);
                    $unitPrice = $item->quantity > 0 ? ($itemTotal / $item->quantity) : 0;

                    $contents[] = [
                        'id' => (string) $product->id,
                        'quantity' => $item->quantity,
                        'item_price' => $unitPrice,
                    ];
                }
            }

            $payload = [
                'data' => [
                    [
                        'event_name' => 'Purchase',
                        'event_time' => time(),
                        'action_source' => 'website',
                        'user_data' => $userData,
                        'custom_data' => [
                            'currency' => 'IDR',
                            'value' => (float) $totalAmount,
                            'contents' => $contents,
                            'content_type' => 'product',
                            'order_id' => $transaction->order_id,
                        ],
                    ],
                ],
            ];

            Http::timeout(5)->post("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token={$accessToken}", $payload);
        } catch (\Exception $e) {
            Log::warning('Meta CAPI Error: ' . $e->getMessage());
        }
    }

    public function approveFraudOrder($id)
    {
        $transaction = Transaction::findOrFail($id);

        if ($transaction->status !== 'on_hold') {
            return response()->json(['message' => 'Status transaksi tidak valid untuk di-approve manual.'], 400);
        }

        $transaction->update(['status' => 'pending']);

        return response()->json(['message' => 'Pesanan berhasil disetujui secara manual.']);
    }

    // public function forceDeleteTransaction(Request $request, $id)
    // {
    //     $transaction = Transaction::with(['details', 'payment'])->find($id);

    //     if (!$transaction) {
    //         return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
    //     }

    //     DB::transaction(function () use ($transaction) {
    //         $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

    //         if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
    //             foreach ($transaction->details as $detail) {
    //                 $this->restoreProductStock($detail->product_id, $detail->quantity);
    //             }
    //         }

    //         // 👇 [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE 👇
    //         if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
    //             PointLedgerService::addPoints(
    //                 $transaction->user_id,
    //                 $transaction->points_used,
    //                 'admin_adjustment',
    //                 "Pengembalian poin karena penghapusan permanen pesanan: {$transaction->order_id}",
    //                 $transaction->id
    //             );
    //         }

    //         if ($transaction->payment) {
    //             $transaction->payment->delete();
    //         }

    //         foreach ($transaction->details as $detail) {
    //             Cache::forget("products.detail.{$detail->product_id}");
    //             $detail->delete();
    //         }

    //         $transaction->delete();
    //     });

    //     Cache::flush();

    //     return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    // }

    public function forceDeleteTransaction(Request $request, $id)
    {
        $transaction = Transaction::with(['details', 'payment'])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        DB::transaction(function () use ($transaction) {
            $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

            if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                // 👇 TAMBAHKAN SORTING ANTI-DEADLOCK 👇
                $sortedDetails = $transaction->details->sortBy('product_id');
                foreach ($sortedDetails as $detail) {
                    $this->restoreProductStock($detail->product_id, $detail->quantity);
                }
            }

            // [PERBAIKAN] GUNAKAN POINT LEDGER SERVICE
            if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                PointLedgerService::addPoints(
                    $transaction->user_id,
                    $transaction->points_used,
                    'admin_adjustment',
                    "Pengembalian poin karena penghapusan permanen pesanan: {$transaction->order_id}",
                    $transaction->id
                );
            }

            if ($transaction->payment) {
                $transaction->payment->delete();
            }

            foreach ($transaction->details as $detail) {
                Cache::forget("products.detail.{$detail->product_id}");
                $detail->delete();
            }

            $transaction->delete();
        });

        Cache::flush();

        return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    }
}
