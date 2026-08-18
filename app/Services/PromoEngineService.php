<?php

namespace App\Services;

use App\Models\DynamicPromo;
use Carbon\Carbon;

class PromoEngineService
{
    /**
     * Hitung promo dinamis yang sedang aktif berdasarkan keranjang saat ini.
     */
    public function calculate($totalValueIDR, $hasBundleProduct)
    {
        $now = Carbon::now('Asia/Jakarta');

        // Ambil promo yang aktif dan tanggalnya masuk (Bisa di-cache nantinya)
        $activePromos = DynamicPromo::where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->get();

        $totalDiscount = 0;
        $freebies = [];
        $promoTags = [];

        foreach ($activePromos as $promo) {
            $rules = $promo->rules;
            $appliedDiscount = 0;

            // 1. Cek Tiering Pembelanjaan (Tiers)
            if (isset($rules['tiers']) && is_array($rules['tiers'])) {
                // Urutkan dari syarat belanja tertinggi ke terendah
                usort($rules['tiers'], function($a, $b) {
                    return $b['min_purchase'] <=> $a['min_purchase'];
                });

                foreach ($rules['tiers'] as $tier) {
                    if ($totalValueIDR >= $tier['min_purchase']) {
                        // Terapkan diskon
                        $appliedDiscount = $tier['discount_nominal'] ?? 0;

                        // Masukkan freebies jika ada
                        if (isset($tier['freebies']) && is_array($tier['freebies'])) {
                            $freebies = array_merge($freebies, $tier['freebies']);
                        }
                        break; // Hentikan loop karena tier tertinggi sudah tercapai
                    }
                }
            }

            // 2. Cek Syarat Pembelian Bundle Khusus
            if ($hasBundleProduct && isset($rules['bundle_reward'])) {
                if (isset($rules['bundle_reward']['freebies'])) {
                    $freebies = array_merge($freebies, $rules['bundle_reward']['freebies']);
                }
            }

            // Gabungkan diskon
            if ($appliedDiscount > 0 || !empty($freebies)) {
                $totalDiscount += $appliedDiscount;

                // Hapus duplikat freebies (misal dapat Pouch dari Tier & dari Bundle)
                $freebies = array_unique($freebies);

                $promoString = $promo->name;
                if (!empty($freebies)) {
                    $promoString .= " (" . implode(", ", $freebies) . ")";
                }
                $promoTags[] = $promoString;
            }
        }

        return [
            'discount_amount' => $totalDiscount,
            'freebies' => array_values($freebies),
            'promo_tag' => !empty($promoTags) ? implode(' + ', $promoTags) : null
        ];
    }
}
