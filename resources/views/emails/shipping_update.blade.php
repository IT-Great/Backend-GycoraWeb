<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f9f9f9; color: #333; padding: 20px; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background-color: #111; color: #ffffff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; text-transform: uppercase; letter-spacing: 1px; }
        .content { padding: 30px; }
        .status-box { background-color: #f0fdf4; border-left: 4px solid #059669; padding: 15px; margin-bottom: 20px; }
        .status-title { font-weight: bold; color: #065f46; margin: 0 0 5px 0; font-size: 16px; }
        .status-text { margin: 0; color: #047857; font-size: 14px; }
        .detail-item { margin-bottom: 8px; font-size: 14px; }
        .tracking-box { text-align: center; margin: 25px 0; padding: 15px; background: #f3f4f6; border: 1px dashed #ccc; border-radius: 6px; }
        .tracking-box h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; text-transform: uppercase; }
        .tracking-box .resi { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #777; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Notifikasi Pengiriman</h1>
        </div>

        <div class="content">
            <p>Halo <strong>{{ $transaction->user->first_name ?? 'Pelanggan' }}</strong>,</p>
            <p>Terdapat pembaruan status pada pengiriman pesanan Anda.</p>

            <div class="status-box">
                <h2 class="status-title">{{ $statusJudul }}</h2>
                <p class="status-text">{{ $statusPesan }}</p>
            </div>

            <div class="detail-item"><strong>Order ID:</strong> {{ $transaction->order_id }}</div>
            <div class="detail-item"><strong>Kurir:</strong> <span style="text-transform: uppercase;">{{ $transaction->courier_company }} - {{ $transaction->courier_type }}</span></div>

            @if($transaction->tracking_number && $transaction->tracking_number !== 'Pending')
            <div class="tracking-box">
                <h3>Nomor Resi Pengiriman</h3>
                <div class="resi">{{ $transaction->tracking_number }}</div>
            </div>
            @endif
        </div>

        <div class="footer">
            <p>Pesan ini dikirimkan otomatis oleh sistem. Harap tidak membalas email ini.</p>
        </div>
    </div>
</body>
</html>
