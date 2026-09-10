@component('mail::message')
# Halo {{ $user->first_name }},

Kabar gembira! Produk favorit Anda **{{ $product->name }}** yang sebelumnya kehabisan stok, kini **sudah tersedia kembali** di gudang Gycora.

Jangan sampai kehabisan lagi! Segera amankan produk Anda sebelum kehabisan oleh pelanggan lain.

@component('mail::button', ['url' => config('app.frontend_url', 'https://gycoraessence.com') . '/product/' . $product->slug, 'color' => 'success'])
Beli Sekarang
@endcomponent

Terima kasih atas kesetiaan Anda,<br>
**Tim {{ config('app.name') }}**
@endcomponent
