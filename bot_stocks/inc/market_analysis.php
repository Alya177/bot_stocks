<?php
// inc/market_analysis.php

function getSwings($prices)
{
    $highs = [];
    $lows = [];
    $side = 4;
    $total = count($prices);

    if ($total < ($side * 2 + 1)) return ['highs' => [], 'lows' => []];

    // PERBAIKAN: i < $total - $side
    // Ini akan menghentikan loop 4 candle sebelum data terakhir (hari ini).
    // Jadi, candle hari ini hanya bertindak sebagai "sayap kanan" konfirmasi,
    // bukan sebagai titik lembah itu sendiri.
    for ($i = $side; $i < $total - $side; $i++) {
        $window = array_slice($prices, $i - $side, ($side * 2) + 1);

        if ($prices[$i] == max($window)) {
            $highs[] = ['val' => $prices[$i], 'idx' => $i];
        }
        if ($prices[$i] == min($window)) {
            $lows[] = ['val' => $prices[$i], 'idx' => $i];
        }
    }
    return ['highs' => $highs, 'lows' => $lows];
}

function getCleanSwings($h_list, $l_list)
{
    // 1. Inisialisasi eksplisit sebagai array kosong
    // Ini krusial untuk mencegah PHP menganggap $all adalah skalar (null/0)
    $all = array();

    // Tambahkan casting (array) untuk memastikan input bukan null
    $h_list = (is_array($h_list)) ? $h_list : [];
    $l_list = (is_array($l_list)) ? $l_list : [];

    foreach ($h_list as $h) {
        if (!isset($h['idx'])) continue; // Pengaman jika data tidak lengkap
        $h['type'] = 'H';
        $all[] = $h; // PHP sekarang menjamin $all adalah array
    }

    foreach ($l_list as $l) {
        if (!isset($l['idx'])) continue;
        $l['type'] = 'L';
        $all[] = $l;
    }

    // 2. Urutkan berdasarkan Index (waktu)
    usort($all, function ($a, $b) {
        return $a['idx'] <=> $b['idx'];
    });

    if (empty($all)) return [];

    // 3. Proses Pembersihan (Validator Pasangan)
    $clean = [];
    $current = $all[0];

    for ($i = 1; $i < count($all); $i++) {
        $next = $all[$i];

        if ($next['type'] === $current['type']) {
            if ($current['type'] === 'L') {
                // Ambil Low yang lebih rendah
                if ((float)$next['val'] < (float)$current['val']) $current = $next;
            } else {
                // Ambil High yang lebih tinggi
                if ((float)$next['val'] > (float)$current['val']) $current = $next;
            }
        } else {
            $clean[] = $current;
            $current = $next;
        }
    }
    $clean[] = $current;
    return $clean;
}


function checkBullishDivergence($prices, $rsi_values, $volumes = [])
{
    // 1. CARI LEMBAH HISTORIS
    $sw = getSwings($rsi_values);
    $l_historis = $sw['lows'];
    if (count($l_historis) < 1) return false;

    $last_confirmed_low_rsi = end($l_historis)['val'];
    $last_confirmed_low_idx = end($l_historis)['idx'];
    $last_confirmed_low_price = $prices[$last_confirmed_low_idx];

    // 2. LOGIKA SIDE 2 (REAL-TIME)
    $count = count($rsi_values);
    if ($count < 5) return false;

    $slice_rsi = array_slice($rsi_values, -5);
    $slice_px  = array_slice($prices, -5);
    $is_local_low_rsi = ($slice_rsi[2] == min($slice_rsi));

    $current_rsi = end($rsi_values);
    $current_price = end($prices);

    // --- LOGIKA FILTER RISIKO ---
    $is_risky = false;

    // 1. Cek kecukupan data (Minimal butuh 20 untuk SMA dan 2 untuk Volume)
    if (count($prices) < 20) {
        $is_risky = true;
    } else {
        // RISK 1: Cek SMA 20 (Trend Filter)
        $sma20 = array_sum(array_slice($prices, -20)) / 20;
        if ($current_price < $sma20) {
            $is_risky = true;
        }
    }

    // RISK 2: Cek Volume secara aman
    // Kita cek dulu apakah array $volumes punya minimal 2 data
    if (!empty($volumes) && count($volumes) >= 2) {
        $current_vol = end($volumes);
        $prev_vol = $volumes[count($volumes) - 2];

        if ($current_vol < $prev_vol) {
            $is_risky = true; // Pantulan tanpa tenaga
        }
    } else {
        // Jika data volume tidak ada atau cuma 1, anggap berisiko karena tidak bisa dibandingkan
        $is_risky = true;
    }

    /**
     * EKSEKUSI DIVERGENCE (HYBRID)
     */
    $price_drop_valid = $current_price < ($last_confirmed_low_price * 0.99);
    $rsi_higher_valid = $current_rsi > ($last_confirmed_low_rsi + 3);
    $was_oversold     = $last_confirmed_low_rsi < 38;
    $is_reversal      = $current_price > $slice_px[2];

    // MODIFIKASI: Hanya return TRUE jika syarat terpenuhi DAN TIDAK RISKY
    if ($is_local_low_rsi && $price_drop_valid && $rsi_higher_valid && $was_oversold && $is_reversal) {
        if ($current_rsi < 48 && !$is_risky) {
            return true;
        }
    }

    return false;
}
//
function getEfficiencyRatio($prices, $period = 10)
{
    if (count($prices) < $period) return 0;
    $slice = array_slice($prices, -$period);
    $net_move = abs(end($slice) - $slice[0]); // Jarak lurus
    $total_sum_move = 0;
    for ($i = 1; $i < count($slice); $i++) {
        $total_sum_move += abs($slice[$i] - $slice[$i - 1]); // Total langkah kaki
    }
    return ($total_sum_move == 0) ? 0 : round($net_move / $total_sum_move, 2);
}

function getMarketStructure($prices, $volumes, $highs, $lows)
{
    if (count($prices) < 100) return null;

    // 1. Dapatkan Swing Points & Clean Structure
    $swings_raw = getSwings($prices);
    $clean_swings = getCleanSwings($swings_raw['highs'], $swings_raw['lows']);

    $h_list_clean = [];
    $l_list_clean = [];
    foreach ($clean_swings as $s) {
        if ($s['type'] === 'H') $h_list_clean[] = $s;
        else $l_list_clean[] = $s;
    }

    if (count($h_list_clean) < 4 || count($l_list_clean) < 3) return null;

    // 2. Definisi Harga Sekarang & Indikator Dasar
    $current = end($prices);
    $all_rsi = calculateFullRSI($prices, 14);
    $current_rsi = end($all_rsi);
    $efficiency = getEfficiencyRatio($prices, 10);

    // 3. Ambil Data Swing Terakhir untuk Analisis
    $h = array_slice($h_list_clean, -3);
    $l = array_slice($l_list_clean, -3);

    $h1 = $h[2]['val'];
    $h1_idx = $h[2]['idx']; // Puncak Terbaru
    $h2 = $h[1]['val'];                        // Puncak Sebelumnya
    $h3 = $h[0]['val'];
    $h3_idx = $h[0]['idx']; // Puncak Wave 1

    $l1 = $l[2]['val']; // Lembah Terbaru (calon Wave 4 atau 2)
    $l2 = $l[1]['val'];
    $l2_idx = $l[1]['idx']; // <-- Tambahkan $l2_idx di sini

    // Index awal Wave 1 (untuk hitung durasi W1)
    $l_ref_idx = count($l_list_clean) - 4;
    $l3_idx = $l_list_clean[$l_ref_idx]['idx'] ?? $l[0]['idx'];

    // 4. Perhitungan Kaidah Elliott (Waktu & Fibonacci)

    // Durasi W1 (Impulse) & W2 (Corrective)
    $duration_W1 = abs($h3_idx - $l3_idx);
    $duration_W2 = abs($l2_idx - $h3_idx);
    $time_ratio = ($duration_W1 > 0) ? ($duration_W2 / $duration_W1) : 0;
    $is_time_proportion_valid = ($time_ratio >= 0.38 && $time_ratio <= 2.61);

    // Rasio Fibonacci (Wave 3 vs Wave 1)
    $w1_size = abs($h3 - ($l_list_clean[$l_ref_idx]['val'] ?? $l2));
    $w3_size = abs($current - $l2);
    $fib_ratio = ($w1_size > 0) ? ($w3_size / $w1_size) : 0;

    // Volume Ratio
    $avg_vol = calculateAvgVolume($volumes, 20);
    $vol_ratio = ($avg_vol > 0) ? round(end($volumes) / $avg_vol, 2) : 1;

    // 5. Kondisi Trend & Divergence
    $macd = calculateMACD($prices);
    $stoch = calculateStochastic(['close' => $prices, 'high' => $highs, 'low' => $lows]);
    $is_major_uptrend = ($l1 > $l2 && $h1 > $h2);
    $is_divergence = checkBullishDivergence($prices, $all_rsi);
    $h_wave1_ref = $h_list_clean[count($h_list_clean) - 4]['val'] ?? $h3;

    // 6. LOGIKA LABELING BERLAPIS
    $label = "🔍 Analysing Structure...";
    $priority = 0;

    if ($efficiency < 0.45) {
        $label = "🌀 Sideways / Complex Noise (WXYXZ)";
        $priority = 2;
    } elseif ($h1 > $h2 && $l1 > $l2) {
        if (!$is_time_proportion_valid) {
            $label = "📈 Slow Trend (Disproportional Time)";
            $priority = 2;
        } else {
            if ($current > $h1) {
                $rsi_at_h1 = $all_rsi[$h1_idx] ?? 50;
                $label = ($current_rsi < $rsi_at_h1) ? "🚨 Wave 5 Ending" : "🏎️ Wave 5 Impulse";
                $priority = ($current_rsi < $rsi_at_h1) ? 2 : 4;
            } elseif ($current < $h1 && $current > $h_wave1_ref) {
                $label = "🧘 Wave 4 Correction (Healthy Dip)";
                $priority = 4;
            } elseif ($current > $h3 && $fib_ratio >= 1.27) { // Naikkan ratio dari 1.27 ke 1.61 (Standar Elliott lebih ketat)
                // Tambahan filter momentum
                $macd_data = $macd['macd'] ?? 0;
                if ($current_rsi > 50 && $macd_data > 0) {
                    $label = "🌊 Wave 3 (Confirmed Impulse)";
                    $priority = 5;
                } else {
                    $label = "🚀 Elliott Wave Progress";
                    $priority = 3;
                }
            } else {
                $label = "🚀 Elliott Wave Progress";
                $priority = 3;
            }
        }
    } elseif ($h1 < $h2 && $l1 > $l2) {
        $label = "📐 ABCDE Triangle (Squeeze)";
        $priority = 4;
    } elseif ($h1 < $h2 && $l1 < $l2) {
        $label = ($current_rsi < 35) ? "📉 ABC Correction (Final Wave C)" : "📉 Major ABC Correction (Bearish)";
        $priority = ($current_rsi < 35) ? 3 : 1;
    }

    // Invalidation Rule
    if ($current < $h_wave1_ref && $is_major_uptrend) {
        $label = "❌ Invalid Impulse (Overlap Wave 4 & 1)";
        $priority = 1;
    }

    // 7. Final Trigger Filter 
    $trigger_info = [];
    $has_gc = (($macd['is_gc'] ?? false) || ($stoch['is_gc'] ?? false));
    $is_high_vol = ($vol_ratio > 1.3); // <-- SEKARANG SUDAH DIDEFINISIKAN

    if ($macd['is_gc'] ?? false) $trigger_info[] = "MACD GC";
    if ($stoch['is_gc'] ?? false) $trigger_info[] = "STOCH GC ✅";
    if ($is_divergence) $trigger_info[] = "BULLISH DIV";
    if ($is_high_vol) $trigger_info[] = "VOL SPIKE 🔥";

    // --- FILTER EKSTRA AGAR TIDAK MURAHAN ---
    // 1. Jika Wave 3 (Priority 5), WAJIB didampingi Volume Spike atau minimal salah satu GC.
    if ($priority == 5 && !$is_high_vol && !$has_gc) return null;

    // 2. Jika cuma "Elliott Progress" biasa (Priority 3) tapi tidak ada GC/Divergence, buang saja.
    if ($priority <= 3 && empty($trigger_info)) return null;

    // Jika tidak ada trigger sama sekali untuk kondisi apapun, jangan kirim.
    if (empty($trigger_info)) return null;
    $w5_projection = $current + ($w1_size * 0.618);

    // RETURN DATA (Sekarang sudah berada di dalam lingkup fungsi yang benar)
    return [
        'label'         => $label,
        'trigger'       => implode(" & ", $trigger_info),
        'is_divergence' => $is_divergence,
        'priority'      => $priority,
        'vol_ratio'     => $vol_ratio,
        'rsi'           => round($current_rsi, 2),
        'stoch_k'       => $stoch['k'] ?? 0,
        'stoch_d'       => $stoch['d'] ?? 0,
        'macd_status'   => ($macd['macd'] > $macd['signal']) ? "Bullish Trend 📈" : "Bearish Trend 📉",
        'tp'            => ($priority == 5) ? round($w5_projection, 0) : $h1,
        'sl'            => $l1 * 0.97
    ];
}
