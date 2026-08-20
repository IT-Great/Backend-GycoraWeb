<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f9fafb;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #111827;
            letter-spacing: 4px;
            text-transform: uppercase;
        }
        .greeting {
            font-size: 16px;
            color: #111827;
        }
        .message-box {
            background-color: #f8fafc;
            border-left: 4px solid #111827;
            padding: 20px;
            margin: 30px 0;
            font-style: italic;
            border-radius: 0 8px 8px 0;
            color: #4b5563;
            font-size: 15px;
        }
        .btn-container {
            text-align: center;
            margin: 40px 0 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background-color: #111827;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            letter-spacing: 1px;
            font-size: 14px;
            text-transform: uppercase;
        }
        .footer {
            margin-top: 40px;
            font-size: 12px;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Gycora Care</h1>
        </div>

        <p class="greeting">Halo,</p>
        <p>Anda telah menerima pesan masuk baru dari <strong>{{ $sender->first_name }} {{ $sender->last_name }}</strong> melalui Pusat Bantuan Gycora.</p>

        <div class="message-box">
            @if($chatMessage->message)
                "{{ $chatMessage->message }}"
            @else
                <em>(Pengguna ini telah melampirkan sebuah dokumen/gambar/video)</em>
            @endif
        </div>

        <div class="btn-container">
            {{-- Mengarahkan ke rute frontend React Anda --}}
            <a href="{{ config('app.frontend_url') }}/chat" class="btn">Balas Pesan Sekarang</a>
        </div>

        <div class="footer">
            <p>Pesan otomatis ini dikirimkan oleh sistem <strong>Gycora Essence</strong>.</p>
            <p>gycora.essence@gmail.com</p>
        </div>
    </div>
</body>
</html>
