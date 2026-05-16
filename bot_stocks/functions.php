<?php
//functions.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__FILE__) . '/inc/';

require_once $base . 'api_handler.php';
require_once $base . 'technical_indicators.php';
require_once $base . 'market_analysis.php';
require_once $base . 'candle_police.php';
require_once $base . 'snr_analysis.php';

function checkInfoA1($prices, $highs, $lows)
{
    // Minimal 110 bar agar perhitungan SMA 100 lebih akurat
    if (count($prices) < 110) return false;

    $prices = array_map('floatval', $prices);
    $highs  = array_map('floatval', $highs);
    $lows   = array_map('floatval', $lows);

    // 1. Kalkulasi Indikator
    $rsi_arr    = trader_rsi($prices, 14);
    $adx_arr    = trader_adx($highs, $lows, $prices, 14);
    $atr_arr    = trader_atr($highs, $lows, $prices, 14);
    $sma50_arr  = trader_sma($prices, 50);
    $sma100_arr = trader_sma($prices, 100); // Parameter 100 sesuai keinginan Anda

    if (!$rsi_arr || !$adx_arr || !$atr_arr || !$sma50_arr || !$sma100_arr) return false;

    $rsi      = end($rsi_arr);
    $adx      = end($adx_arr);
    $prev_adx = $adx_arr[count($adx_arr) - 2];
    $atr      = end($atr_arr);
    $sma50    = end($sma50_arr);
    $sma100   = end($sma100_arr);
    $price    = end($prices);

    // 2. Filter Utama (VCP-ish & Accumulation)
    $condRSI = ($rsi >= 40 && $rsi <= 55);
    $condADX = ($adx < 22);
    $condSMA = ($sma50 < $sma100);

    // Perhitungan Volatilitas: ATR harus kecil (harga "tenang")
    $atr_percentage = ($atr / $price) * 100;
    $condVolDynamic = ($atr_percentage < 2.5);

    // Konfirmasi Momentum
    $adx_is_turning_up = ($adx > $prev_adx);

    if ($condRSI && $condADX && $condVolDynamic && $condSMA) {
        return [
            'type'  => ($adx_is_turning_up) ? "SUPER_A1" : "INFO_A1",
            'adx'   => round($adx, 2),
            'atr_p' => round($atr_percentage, 2),
            'sma50' => round($sma50, 0),
            'sma100' => round($sma100, 0)
        ];
    }
    return false;
}

/**
 * DETEKSI VOLATILITY CONTRACTION (VCP) & DRY-OUT
 * Mengembalikan array status untuk indikasi ledakan
 */
function checkExplosionPotential($highs, $lows, $volumes, $prices)
{
    $count = count($prices);
    if ($count < 20) return null;

    // --- A. LOGIKA VOLUME DRY-OUT (VDO) ---
    $avg_vol_20 = array_sum(array_slice($volumes, -20)) / 20;
    $current_vol = end($volumes);
    // Kering jika volume < 50% rata-rata
    $is_dry_out = ($current_vol < ($avg_vol_20 * 0.5));

    // --- B. LOGIKA VCP (Penciutan Rentang Harga) ---
    $ranges = [];
    for ($i = 1; $i <= 3; $i++) {
        $idx = $count - $i;
        $ranges[] = $highs[$idx] - $lows[$idx];
    }
    // Cek apakah rentang makin mengecil (Tighter)
    $is_vcp = ($ranges[0] < $ranges[1] && $ranges[1] < $ranges[2]);

    // --- C. LOGIKA VOLUME SPIKE (Pemanasan) ---
    $prev_vols = array_slice($volumes, -10, 9); // Volume 9 hari lalu
    $max_prev_vol = max($prev_vols);
    $is_vol_spike = ($current_vol > $max_prev_vol && end($prices) > $prices[$count - 2]);

    return [
        'is_dry_out' => $is_dry_out,
        'is_vcp'     => $is_vcp,
        'is_spike'   => $is_vol_spike,
        'vol_ratio'  => round($current_vol / $avg_vol_20, 2)
    ];
}

function checkBullReady($prices, $highs, $lows, $volumes)
{
    if (count($prices) < 110) return false;

    $price = end($prices);

    // 1. Indikator Dasar
    $rsi_arr = trader_rsi($prices, 14);
    $adx_arr = trader_adx($highs, $lows, $prices, 14);
    $sma50_arr = trader_sma($prices, 50);
    $sma100_arr = trader_sma($prices, 100);

    if (!$rsi_arr || !$adx_arr || !$sma50_arr || !$sma100_arr) return false;

    $rsi = end($rsi_arr);
    $rsi_prev = $rsi_arr[count($rsi_arr) - 2];
    $rsi_3d = $rsi_arr[count($rsi_arr) - 4];

    $adx = end($adx_arr);
    $sma50 = end($sma50_arr);
    $sma100 = end($sma100_arr);

    // 2. Kondisi Major Uptrend
    $is_uptrend = ($price > $sma50 && $sma50 > $sma100);

    // 3. Filter RSI Menukik (Velocity Filter) - ANTI JEBAKAN MAPI
    // Menolak sinyal jika RSI jatuh > 3 poin dalam 1 hari atau > 7 poin dalam 3 hari
    $is_rsi_diving = ($rsi < $rsi_prev - 3.0) || ($rsi < $rsi_3d - 7.0);

    // 4. Filter Momentum Hot Zone (Divergence Check)
    // Sinyal hanya valid jika RSI stabil atau menguat (Slope Positif/Netral)
    $is_strong_momentum = ($adx > 20 && $rsi >= 50 && $rsi <= 68 && !$is_rsi_diving);

    // 5. Kondisi Kepadatan Harga (Tightness)
    $last_5 = array_slice($prices, -5);
    $is_tight = ((max($last_5) - min($last_5)) / min($last_5) * 100 < 4.0);

    // 6. Logika VDO (Volume Dry-Out)
    $avg_vol_20 = array_sum(array_slice($volumes, -20)) / 20;
    $current_vol = end($volumes);
    $is_vdo = ($current_vol < ($avg_vol_20 * 0.65));

    // 7. Filter Candle Rejection (Upper Shadow)
    $last_open = $prices[count($prices) - 2]; // Asumsi harga penutupan kemarin adalah pembukaan hari ini jika data harian
    $last_high = end($highs);
    $upper_shadow = $last_high - max($last_open, $price);
    $body = abs($price - $last_open);
    $no_rejection = ($upper_shadow < $body); // Menolak jika ekor atas lebih panjang dari body

    // Eksekusi Final
    if ($is_uptrend && $is_strong_momentum && $is_tight && $is_vdo && $no_rejection) {
        return [
            'rsi'       => round($rsi, 2), // Menggunakan $rsi yang sudah ada di atas
            'vol_ratio' => round($current_vol / $avg_vol_20, 2), // Menggunakan $current_vol
            'range_pct' => round((max($last_5) - min($last_5)) / min($last_5) * 100, 2), // Perhitungan langsung tightness
            'sma_dist'  => round(($price - $sma50) / $sma50 * 100, 2) // Jarak ke SMA50
        ];
    }

    return false;
}

function getADXData($highs, $lows, $prices, $period = 14)
{
    if (count($prices) < ($period * 2 + 1)) return null;

    // Pastikan data float untuk trader extension
    $highs = array_map('floatval', $highs);
    $lows = array_map('floatval', $lows);
    $prices = array_map('floatval', $prices);

    $adx_arr = trader_adx($highs, $lows, $prices, $period);
    $plus_di = trader_plus_di($highs, $lows, $prices, $period);
    $minus_di = trader_minus_di($highs, $lows, $prices, $period);

    if (!$adx_arr) return null;

    return [
        'adx' => end($adx_arr),
        'prev_adx' => $adx_arr[count($adx_arr) - 2],
        'plus_di' => end($plus_di),
        'minus_di' => end($minus_di)
    ];
}

/**
 * Menentukan fase pasar menggunakan Hilbert Transform - Trend vs Cycle Mode
 * Membantu menentukan apakah indikator Oscillator (Stochastic/RSI) 
 * masih valid atau malah "terseret" trend.
 */
function getMarketPhase($close_prices)
{
    if (count($close_prices) < 63) { // HT minimal butuh sekitar 63 data untuk stabil
        return ["mode" => "UNKNOWN", "label" => "Data Kurang"];
    }

    // Mendapatkan Trend Mode (1 = Trending, 0 = Cycling/Sideways)
    $trend_mode_arr = trader_ht_trendmode($close_prices);

    if ($trend_mode_arr === false) return ["mode" => "ERROR", "label" => "HT Failed"];

    $current_mode = end($trend_mode_arr);

    // Untuk menentukan arah Trend (Uptrend/Downtrend), kita tetap butuh 
    // pembanding harga atau HT_TRENDLINE
    $ht_line_arr = trader_ht_trendline($close_prices);
    $current_ht = end($ht_line_arr);
    $price = end($close_prices);

    if ($current_mode == 1) {
        // Sedang Trending
        if ($price > $current_ht) {
            return ["mode" => "TRENDING", "label" => "UPTREND", "val" => 1];
        } else {
            return ["mode" => "TRENDING", "label" => "DOWNTREND", "val" => 1];
        }
    } else {
        // Sedang Sideways / Cycling
        return ["mode" => "CYCLING", "label" => "SIDEWAYS/ACCUMULATION", "val" => 0];
    }
}

function checkRubberBand($prices, $highs, $lows, $volumes, $rsi_history)
{
    $period = 20;
    if (count($prices) < $period) return false;

    // 1. Kalkulasi Dasar (SMA & Bollinger)
    $current_price = end($prices);
    $slice = array_slice($prices, -$period);
    $sma20 = array_sum($slice) / $period;

    $variance = 0;
    foreach ($slice as $p) $variance += pow($p - $sma20, 2);
    $std_dev = sqrt($variance / $period);
    $extreme_lower_band = $sma20 - ($std_dev * 2.5);

    // 2. Kalkulasi Indikator Pendukung
    $rsi = end($rsi_history);
    $prev_rsi = $rsi_history[count($rsi_history) - 2];
    $avg_vol = array_sum(array_slice($volumes, -20)) / 20;
    $current_vol = end($volumes);

    // 3. Filter Validasi (Anti Pisau Jatuh)
    $is_oversold = ($rsi <= 32); // Sudah sangat murah
    $is_rsi_hook = ($rsi > $prev_rsi); // Tenaga jual mulai berkurang
    $is_vol_climax = ($current_vol > $avg_vol * 1.5); // Ada aksi borong saat panik
    $gap_pct = (($current_price - $sma20) / $sma20) * 100;

    // Kriteria: Harga di bawah band ekstrem DAN RSI mulai balik arah
    if ($current_price <= $extreme_lower_band && $is_oversold && $is_rsi_hook) {
        return [
            'gap' => round($gap_pct, 2),
            'sma20' => round($sma20, 2),
            'rsi' => round($rsi, 2),
            'is_climax' => $is_vol_climax
        ];
    }

    return false;
}

function checkWyckoffSpring($prices, $lows, $volumes, $rsi_history)
{
    $lookback = 20;
    if (count($prices) < 30) return false;

    // 1. Cari Support terendah dalam 20 hari terakhir (sebelum hari ini)
    $history_lows = array_slice($lows, - ($lookback + 1), $lookback);
    $support_level = min($history_lows);

    // 2. Data Hari Ini & Kemarin
    $current_close = end($prices);
    $current_low = end($lows);
    $prev_low = $lows[count($lows) - 2];
    $rsi = end($rsi_history);
    $prev_rsi = $rsi_history[count($rsi_history) - 2];

    // Tambahan Perbaikan: Validasi bahwa penetrasi support adalah titik terdalam dari siklus pendek (5 hari)
    $last_5_lows = array_slice($lows, -5);
    $absolute_recent_low = min($last_5_lows);

    // Pastikan titik terendah 5 hari terakhir memang berada di bawah support (konfirmasi letak trap)
    if ($absolute_recent_low >= $support_level) return false;

    // 3. Syarat Spring:
    // - Low hari ini (atau kemarin) sempat menembus Support (False Breakdown)
    // - Close hari ini harus sudah kembali DI ATAS Support
    $was_breakdown = ($current_low < $support_level || $prev_low < $support_level);
    $is_reclaimed = ($current_close > $support_level);

    // 4. Validasi Anti-Pisau Jatuh (RSI Hook & Momentum)
    $is_rsi_valid = ($rsi > $prev_rsi); // RSI mulai naik

    if ($was_breakdown && $is_reclaimed && $is_rsi_valid) {
        return [
            'support' => $support_level,
            'trap_low' => $current_low,
            'rsi_val' => round($rsi, 2)
        ];
    }
    return false;
}

function calculateFairValue($sector, $eps, $bvps)
{
    if ($eps <= 0) return 0; // Skip jika laba negatif

    switch ($sector) {
        case 'Financials':
        case 'Banks':
            // Metode PBV Band (Cocok untuk Perbankan)
            return $bvps * 1.8; // Angka 1.8 adalah rata-rata PBV wajar perbankan

        case 'Energy':
        case 'Basic Materials':
            // Metode Graham Number (Cocok untuk Tambang/Energi)
            if ($bvps <= 0) return 0;
            return sqrt(22.5 * $eps * $bvps);

        case 'Technology':
        case 'Consumer Cyclicals':
            // Metode PE Standar Growth (Contoh PE 15-20)
            return $eps * 18;

        default:
            // Sektor lainnya menggunakan PE standar 15
            return $eps * 15;
    }
}

/**
 * Strategi Blue Sky Breakout
 * Mendeteksi harga menembus High 20 Hari dan berada di atas SMA 100
 */
function checkBlueSky($prices, $highs, $volumes)
{
    $count = count($prices);
    // Sekarang hanya butuh minimal 100 data
    if ($count < 100) return false;

    $current_price = end($prices);

    // 1. Tetap gunakan High 20 hari untuk breakout
    $highest_20d = max(array_slice($highs, -21, 20));

    // 2. Ubah SMA 200 menjadi SMA 100
    $sma100 = array_sum(array_slice($prices, -100)) / 100;

    // --- LOGIKA BREAKOUT ---
    $is_breakout = ($current_price > $highest_20d);

    // Gunakan SMA 100 sebagai filter tren
    $is_bullish_trend = ($current_price > $sma100);

    if ($is_breakout && $is_bullish_trend) {
        return [
            'breakout_price' => $highest_20d,
            'sma_used'       => 100,
            'sma_val'        => round($sma100, 0)
        ];
    }
    return false;
}

/**
 * Strategi Golden Squeeze Evolution
 * Fokus: Deteksi akumulasi volatilitas rendah sebelum ledakan harga (Pre-Explosion)
 */
function checkGoldenSqueeze($prices, $highs, $lows)
{
    $count = count($prices);
    if ($count < 30) return false;

    // 1. Hitung Bollinger Bands (20, 2)
    $sma20 = array_sum(array_slice($prices, -20)) / 20;
    $sum_sq = 0;
    foreach (array_slice($prices, -20) as $p) {
        $sum_sq += pow($p - $sma20, 2);
    }
    $std_dev = sqrt($sum_sq / 20);
    $upper_bb = $sma20 + (2 * $std_dev);
    $lower_bb = $sma20 - (2 * $std_dev);

    // 2. Hitung Keltner Channel (20, 1.5 ATR) menggunakan fungsi ATR yang sudah ada
    // Misal ATR 20 harian dihitung sederhana
    $atr_arr = trader_atr($highs, $lows, $prices, 20);
    $atr20 = $atr_arr ? end($atr_arr) : 0;
    $upper_kc = $sma20 + (1.5 * $atr20);
    $lower_kc = $sma20 - (1.5 * $atr20);

    // --- LOGIKA SQUEEZE ---
    // Squeeze terjadi jika Bollinger Bands menciut ke dalam Keltner Channels
    $is_squeezing = ($upper_bb < $upper_kc) && ($lower_bb > $lower_kc);

    // Konfirmasi Momentum (MACD Histogram harus mulai naik/Uptick)
    $macd = calculateMACD($prices);
    $is_momentum_up = ($macd['histogram'][0] > $macd['histogram'][1]);

    if ($is_squeezing && $is_momentum_up) {
        return [
            'squeeze_tightness' => round(($upper_bb - $lower_bb) / $sma20 * 100, 2),
            'trend_bias' => $macd['slope'],
            'mid_line' => round($sma20, 0)
        ];
    }

    return false;
}

function calculateMFI($highs, $lows, $prices, $volumes, $period = 14)
{
    if (count($prices) < $period + 1) return null;

    // Pastikan semua data float
    $highs = array_map('floatval', $highs);
    $lows = array_map('floatval', $lows);
    $prices = array_map('floatval', $prices);
    $volumes = array_map('floatval', $volumes);

    $mfi_arr = trader_mfi($highs, $lows, $prices, $volumes, $period);

    if (!$mfi_arr) return null;

    return [
        'today' => end($mfi_arr),
        'yesterday' => $mfi_arr[count($mfi_arr) - 2],
        'slope' => end($mfi_arr) - $mfi_arr[count($mfi_arr) - 2]
    ];
}
