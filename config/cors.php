<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // 1. Pastikan path yang membutuhkan CORS tercakup di sini.
    // Tambahkan 'broadcasting/auth' jika belum ada, atau gunakan wildcard 'api/*'
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    // 2. Metode HTTP yang diizinkan
    'allowed_methods' => ['*'],

    // 3. Domain frontend yang diizinkan (Netlify dan Localhost)
    // GANTI '*' MENJADI DOMAIN FRONTEND ANDA SECARA SPESIFIK UNTUK PRODUCTION
    'allowed_origins' => [
        'http://localhost:5173',          // Untuk development lokal
        'http://127.0.0.1:5173',
        'https://gycoraessence.netlify.app', // Domain Frontend Production Anda
        'https://gycoraessence.com',
        'https://www.gycoraessence.com'
        // Jika ada www atau variasi lain, tambahkan juga
    ],

    // 4. Pola Origin (opsional, biarkan kosong jika sudah diisi allowed_origins)
    'allowed_origins_patterns' => [],

    // 5. Header yang diizinkan dikirim oleh frontend
    'allowed_headers' => ['*'],

    // 6. Header yang diekspos ke frontend
    'exposed_headers' => [],

    // 7. Berapa lama browser boleh me-cache hasil preflight request (dalam detik)
    'max_age' => 0,

    // 8. SANGAT PENTING UNTUK AUTENTIKASI: Harus diset true
    'supports_credentials' => true,
];
