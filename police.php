<?php
// police.php

/**
 * MOMENTUM POLICE - RSI SLOPE & VOLATILITY AUDIT
 * Menggabungkan kekuatan RSI Slope, ATR 20D, dan Hard Limit 5%
 *
 * ALUR FAIL-FAST (dari paling murah ke paling mahal komputasinya):
 *  1. Data cukup?
 *  2. NATR & StdDev (veto saham liar & overbought deviasi)
 *  3. RSI Slope (syarat mutlak — negatif langsung reject)
 *  4. ATR & Hard Limit (anti terlanjur naik)
 *  5. MFI (veto distribusi smart money) ← DIPERBAIKI: dipindah ke sini
 *  6. Switch area RSI (bottom / transisi / high momentum)
 */
function isMomentumValid($rsi_history, $vol_ratio = 1.0, $stoch_data = null, $atr_20d = null, $price_data = null, $mfi_data = null)
{
    $count = count($rsi_history);
    if ($count < 2) return false;

    // =========================================================
    // TAHAP 1: STATISTIK NATR & STDDEV
    // =========================================================
    $prices = $price_data['all_close'] ?? [];
    $highs  = $price_data['all_high']  ?? [];
    $lows   = $price_data['all_low']   ?? [];

    $dynamic_hard_limit = 4.0; // Default

    if (count($prices) >= 20) {
        // A. Hitung NATR untuk ukur "keliaran" saham
        $natr_series  = trader_natr($highs, $lows, $prices, 20);
        $current_natr = end($natr_series);

        // B. Hitung StdDev untuk batas overbought
        $std_dev_series = trader_stddev($prices, 20, 2);
        $sma20_series   = trader_sma($prices, 20);
        $upper_band     = end($sma20_series) + (2 * end($std_dev_series));

        // Veto 1: Harga di luar deviasi normal (overbought statistik)
        if (end($prices) > $upper_band) {
            echo "LOG: VETO STATISTIK - Harga di luar deviasi normal (Upper Band Overbought).\n";
            return false;
        }

        // Veto 2: Perketat hard limit jika saham terlalu liar
        if ($current_natr > 5.0) {
            $dynamic_hard_limit = 3.0;
            echo "LOG: INFO - Volatilitas Tinggi (" . round($current_natr, 2) . "%), Limit diperketat ke 3%.\n";
        }
    }

    // =========================================================
    // TAHAP 2: RSI SLOPE (SYARAT MUTLAK — FAIL-FAST)
    // =========================================================
    $rsi_today     = end($rsi_history);
    $rsi_yesterday = $rsi_history[$count - 2];
    $rsi_slope     = $rsi_today - $rsi_yesterday;

    if ($rsi_slope <= 0) return false;

    // =========================================================
    // TAHAP 3: ATR & HARD LIMIT (ANTI TERLANJUR NAIK)
    // =========================================================
    if ($price_data && $atr_20d) {
        $price_today = $price_data['today'];
        $price_prev  = $price_data['yesterday'];
        $high_today  = $price_data['high'] ?? $price_today;
        $low_today   = $price_data['low']  ?? $price_today;

        // Veto: Harga sudah lari terlalu jauh dari low hari ini vs ATR
        $atr_consumed = ($price_today - $low_today) / $atr_20d;
        if ($atr_consumed > 0.5) {
            echo "LOG: VETO MARGIN - Harga sudah lari {$atr_consumed}x ATR. Sisa margin profit terlalu tipis.\n";
            return false;
        }

        // Veto: Range harian sudah melampaui 1.2x ATR (saham sudah lelah)
        $daily_range_nominal = $high_today - $low_today;
        if ($daily_range_nominal > ($atr_20d * 1.2)) {
            echo "LOG: VETO ATR RANGE - Saham sudah lelah (Range: $daily_range_nominal > 1.2x ATR).\n";
            return false;
        }

        $pct_change = ($price_today - $price_prev) / $price_prev * 100;

        // Veto: Naik > 2% tapi melebihi 1.25x volatilitas ATR
        if ($pct_change > 2.0) {
            $volatility_threshold = ($atr_20d / $price_prev) * 100 * 1.25;
            if ($pct_change > $volatility_threshold) {
                echo "LOG: VETO ATR - Naik > 2.0% dan Melebihi 1.25x Volatilitas ATR ($pct_change%).\n";
                return false;
            }
        }

        // Hard Limit absolut (default 4%, diperketat ke 3% jika NATR > 5)
        if ($pct_change > $dynamic_hard_limit) {
            echo "LOG: VETO HARD LIMIT - Harga sudah naik ekstrim $pct_change% (Limit: $dynamic_hard_limit%).\n";
            return false;
        }
    }

    // =========================================================
    // TAHAP 4: MFI — VETO DISTRIBUSI SMART MONEY
    // PERBAIKAN: Blok ini dipindah ke sini dari bawah switch RSI
    // agar benar-benar dieksekusi sebelum return di tiap case RSI.
    // =========================================================
    if ($mfi_data) {
        $mfi_now   = $mfi_data['today'];
        $mfi_slope = $mfi_data['slope'];

        // Veto ADX: Jika tren terlalu lemah, sinyal tidak valid
        if (count($highs) >= 20) {
            $adx_series  = trader_adx($highs, $lows, $prices, 14);
            $current_adx = end($adx_series);
            if ($current_adx < 20) {
                echo "LOG: VETO ADX - Tren terlalu lemah (" . round($current_adx, 2) . ").\n";
                return false;
            }
        }

        // Veto MFI 1: Smart Money keluar (distribusi terdeteksi)
        if ($mfi_slope < -2.0) {
            echo "LOG: VETO MFI - Distribusi Terdeteksi (MFI Slope: $mfi_slope).\n";
            return false;
        }

        // Veto MFI 2: RSI pucuk tapi uang tidak masuk
        if ($rsi_today > 65 && $mfi_now < 60) {
            echo "LOG: VETO MFI - Harga Pucuk tapi Uang Tidak Masuk (MFI: $mfi_now).\n";
            return false;
        }
    }

    // =========================================================
    // TAHAP 5: SWITCH AREA RSI
    // Semua veto di atas sudah lolos — tinggal audit per zona RSI.
    // =========================================================

    // CASE 1: AREA BOTTOM (RSI < 40)
    if ($rsi_today < 40) {
        if ($stoch_data && isset($stoch_data['k'], $stoch_data['prev_k'])) {
            $s_slope = $stoch_data['k'] - $stoch_data['prev_k'];
            if ($s_slope < 5) return false;
        }
        return ($rsi_slope >= 0.8);
    }

    // CASE 2: AREA TRANSISI / TRAP ZONE (RSI 40 - 65)
    if ($rsi_today >= 40 && $rsi_today <= 65) {
        $min_slope = 1.2;
        if ($vol_ratio < 1.5) {
            $min_slope = 2.5;
        }
        return ($rsi_slope >= $min_slope);
    }

    // CASE 3: AREA HIGH MOMENTUM (RSI > 65)
    if ($rsi_today > 65) {
        if ($vol_ratio < 1.2) return false;
        return ($rsi_slope >= 1.6);
    }

    // Safety net — seharusnya tidak pernah sampai sini
    // karena RSI pasti masuk salah satu dari 3 case di atas
    return false;
}
