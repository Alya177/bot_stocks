<?php
// inc/snr_analysis.php

function getDynamicZones($prices, $h_raw, $l_raw, $h_data, $l_data) {
    $current_price = end($prices);
    $total_candle = count($prices);
    
    $atr_series = trader_atr($h_data, $l_data, $prices, 14);
    $current_atr = end($atr_series) ?: ($current_price * 0.02);
    
    // Jarak minimal antar level agar tidak tumpuk (Clustering)
    $cluster_threshold = $current_atr * 0.5; 

    // Ambil struktur asli (HL HL)
    $clean_swings = getCleanSwings($h_raw, $l_raw);
    $reversed = array_reverse($clean_swings);

    $found_supports = [];
    $found_resistances = [];

    // UBAH DISINI: Mulai pindai dari 4 candle yang lalu
    // Karena fractal side 4 baru terkonfirmasi setelah candle ke-4 (konfirmasi sayap kanan)
    $min_index_allowed = ($total_candle - 1) - 4;

    foreach ($reversed as $s) {
        // Lewati jika fractal belum matang (kurang dari 4 candle)
        if ($s['idx'] > $min_index_allowed) continue;

        $price_val = (float)$s['val'];

        // Support: Lembah di bawah harga sekarang
        if ($s['type'] === 'L' && $price_val < $current_price) {
            if (count($found_supports) < 2) {
                if (empty($found_supports) || abs(end($found_supports) - $price_val) >= $cluster_threshold) {
                    $found_supports[] = $price_val;
                }
            }
        } 
        
        // Resistance: Bukit di atas harga sekarang
        else if ($s['type'] === 'H' && $price_val > $current_price) {
            if (count($found_resistances) < 2) {
                if (empty($found_resistances) || abs(end($found_resistances) - $price_val) >= $cluster_threshold) {
                    $found_resistances[] = $price_val;
                }
            }
        }

        if (count($found_supports) >= 2 && count($found_resistances) >= 2) break;
    }

    rsort($found_supports);
    sort($found_resistances);

    return [
        'support_1' => isset($found_supports[0]) ? [
            'base' => round($found_supports[0], 0),
            'zone_min' => round($found_supports[0] * 0.985, 0),
            'zone_max' => round($found_supports[0] * 1.01, 0)
        ] : null,
        'support_2' => isset($found_supports[1]) ? ['base' => round($found_supports[1], 0)] : null,
        'resistance_1' => $found_resistances[0] ?? null,
        'resistance_2' => $found_resistances[1] ?? null,
        'is_in_zone' => (isset($found_supports[0]) && $current_price >= ($found_supports[0] * 0.985) && $current_price <= ($found_supports[0] * 1.01)),
        'info' => [
            'atr' => round($current_atr, 2),
            'threshold' => round($current_atr * 2, 2),
            'scan_start_idx' => $min_index_allowed
        ]
    ];
}