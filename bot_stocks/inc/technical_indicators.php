<?php

/**
 * inc/technical_indicators.php
 * Module: Core Technical Analysis Indicators
 * Last Update: Disiplin Golden Cross 2 Hari (H-0 & H-1)
 */

// --- FUNGSI EMA (Exponential Moving Average) ---
function calculateEMA($data, $period)
{
    if (count($data) < $period) return [];

    $ema = [];
    $multiplier = 2 / ($period + 1);

    // Initial SMA sebagai titik awal EMA
    $initial_sma = array_sum(array_slice($data, 0, $period)) / $period;
    $ema[] = $initial_sma;

    for ($i = $period; $i < count($data); $i++) {
        $last_ema = end($ema);
        $ema[] = ($data[$i] - $last_ema) * $multiplier + $last_ema;
    }
    return $ema;
}

// --- FUNGSI MACD (Moving Average Convergence Divergence) ---
function calculateMACD($prices)
{
    if (count($prices) < 40) return null;

    $ema12 = calculateEMA($prices, 12);
    $ema26 = calculateEMA($prices, 26);

    if (empty($ema12) || empty($ema26)) return null;
    $offset = count($ema12) - count($ema26);
    if ($offset < 0) return null;

    $macd = [];
    for ($i = 0; $i < count($ema26); $i++) {
        $macd[] = $ema12[$i + $offset] - $ema26[$i];
    }

    $signal = calculateEMA($macd, 9);
    if (count($macd) < 4 || count($signal) < 4) return null;

    $m = array_slice($macd, -4);
    $s = array_slice($signal, -4);

    // Hitung Histogram (MACD - Signal)
    $h_today     = $m[3] - $s[3];
    $h_yesterday = $m[2] - $s[2];

    // Logic Fresh GC MACD (H-0 & H-1)
    $gc_today     = ($m[2] <= $s[2] && $m[3] > ($s[3] + 0.001));
    $gc_yesterday = ($m[1] <= $s[1] && $m[2] > ($s[2] + 0.001));

    return [
        'macd'      => $m[3],
        'signal'    => $s[3],
        'histogram' => [$h_today, $h_yesterday],
        'is_gc'     => ($gc_today || $gc_yesterday),
        'slope'     => ($m[3] > $m[2]) ? 'UP' : 'DOWN'
    ];
}

// --- FUNGSI STOCHASTIC (Disiplin 2 Hari) ---
function calculateStochastic($data_history)
{
    $prices = $data_history['close'] ?? [];
    $highs  = $data_history['high']  ?? [];
    $lows   = $data_history['low']   ?? [];

    if (count($prices) < 30) return null;

    $k_period = 14;
    $k_smoothing = 3;
    $d_smoothing = 3;

    // 1. Fast %K
    $fast_k = [];
    for ($i = $k_period - 1; $i < count($prices); $i++) {
        $slice_high   = array_slice($highs, $i - ($k_period - 1), $k_period);
        $slice_low    = array_slice($lows, $i - ($k_period - 1), $k_period);
        $highest_high = max($slice_high);
        $lowest_low   = min($slice_low);

        $fast_k[] = ($highest_high == $lowest_low) ? 0 : (($prices[$i] - $lowest_low) / ($highest_high - $lowest_low)) * 100;
    }

    // 2. Slow %K (Smoothing)
    $slow_k = [];
    for ($i = $k_smoothing; $i <= count($fast_k); $i++) {
        $slow_k[] = array_sum(array_slice($fast_k, $i - $k_smoothing, $k_smoothing)) / $k_smoothing;
    }

    // 3. Slow %D (Smoothing dari Slow %K)
    $slow_d = [];
    for ($i = $d_smoothing; $i <= count($slow_k); $i++) {
        $slow_d[] = array_sum(array_slice($slow_k, $i - $d_smoothing, $d_smoothing)) / $d_smoothing;
    }

    $count_k = count($slow_k);
    $count_d = count($slow_d);
    if ($count_d < 4) return null;

    // Ambil Snapshot 3 Titik Terakhir (H-0, H-1, H-2)
    $k = ['today' => $slow_k[$count_k - 1], 'yest' => $slow_k[$count_k - 2], 'lusa' => $slow_k[$count_k - 3]];
    $d = ['today' => $slow_d[$count_d - 1], 'yest' => $slow_d[$count_d - 2], 'lusa' => $slow_d[$count_d - 3]];

    // DISIPLIN 2 HARI: GC hanya valid jika terjadi di H-0 atau H-1
    $gc_today     = ($k['yest'] <= $d['yest'] && $k['today'] > ($d['today'] + 0.2) && $k['today'] > $k['yest']);
    $gc_yesterday = ($k['lusa'] <= $d['lusa'] && $k['yest'] > ($d['yest'] + 0.2) && $k['yest'] > $k['lusa']);

    return [
        'k'      => round($k['today'], 2),
        'prev_k' => round($k['yest'], 2),
        'd'      => round($d['today'], 2),
        'is_gc'  => ($gc_today || $gc_yesterday)
    ];
}

// --- FUNGSI RSI (Wilder's Smoothing) ---
function calculateFullRSI($prices, $period = 14)
{
    if (count($prices) < $period + 1) return array_fill(0, count($prices), 50);

    $rsi_history = [];
    $gains  = [];
    $losses = [];

    for ($i = 1; $i < count($prices); $i++) {
        $diff     = $prices[$i] - $prices[$i - 1];
        $gains[]  = $diff > 0 ? $diff : 0;
        $losses[] = $diff < 0 ? abs($diff) : 0;
    }

    $avg_gain = array_sum(array_slice($gains, 0, $period)) / $period;
    $avg_loss = array_sum(array_slice($losses, 0, $period)) / $period;

    for ($i = 0; $i < $period; $i++) {
        $rsi_history[] = 50;
    }

    $rs = ($avg_loss == 0) ? 100 : $avg_gain / $avg_loss;
    $rsi_history[] = 100 - (100 / (1 + $rs));

    for ($i = $period; $i < count($gains); $i++) {
        $avg_gain = (($avg_gain * ($period - 1)) + $gains[$i]) / $period;
        $avg_loss = (($avg_loss * ($period - 1)) + $losses[$i]) / $period;

        if ($avg_loss == 0) {
            $rsi_history[] = 100;
        } else {
            $rs = $avg_gain / $avg_loss;
            $rsi_history[] = 100 - (100 / (1 + $rs));
        }
    }
    return $rsi_history;
}

// --- FUNGSI VOLUME AVERAGE ---
function calculateAvgVolume($volumes, $period = 20)
{
    if (count($volumes) < $period + 1) return 0;
    return array_sum(array_slice($volumes, - ($period + 1), $period)) / $period;
}
