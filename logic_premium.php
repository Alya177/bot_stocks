<?php
// logic_premium.php

/** @var array $prices */
/** @var array $highs */
/** @var array $lows */
/** @var array $volumes */
/** @var array $rsi_history */
/** @var float $rsi */
/** @var string $ticker */
/** @var mysqli $conn */
/** @var float $current_price */
/** @var float $k_val */
/** @var float $adj_vol_power */
/** @var string $is_infoA1 */
/** @var float $vol_for_scoring */
/** @var array $mfi_results */
/** @var array $stoch */
/** @var float $current_atr_20d */
/** @var array $price_audit */
/** @var array $adx_data */


if (!isset($adx_data) || $adx_data === null) {
    $adx_data = getADXData($highs, $lows, $prices, 14);
}

$trend_mode_series = trader_ht_trendmode($prices);
$is_market_trending = (is_array($trend_mode_series) && end($trend_mode_series) == 1);

// --- [INISIALISASI DATA DARI FUNGSI SNR ANALYSIS] ---
$total_candle = count($prices);

// 1. Jalankan fungsi SNR secara internal untuk mendapatkan zona
$swings_raw = getSwings($prices);
$snr = getDynamicZones($prices, $swings_raw['highs'], $swings_raw['lows'], $highs, $lows);

// 2. Tarik titik High/Low terakhir yang digunakan oleh SNR
// Kita ambil dari struktur 'reversed' yang sudah melewati konfirmasi 4 candle
$clean_swings_full = getCleanSwings($swings_raw['highs'], $swings_raw['lows']);
$reversed_confirmed = array_reverse($clean_swings_full);
$min_idx_confirmed = ($total_candle - 1) - 4; // Sinkron dengan snr_analysis.php line 25

$last_h = null;
$last_h_idx = null;
$last_l = null;
$last_l_idx = null;

foreach ($reversed_confirmed as $s) {
    if ($s['idx'] > $min_idx_confirmed) continue; // Wajib terkonfirmasi sayap kanan

    if ($s['type'] === 'H' && $last_h === null) {
        $last_h = (float)$s['val'];
        $last_h_idx = $s['idx'];
    }
    if ($s['type'] === 'L' && $last_l === null) {
        $last_l = (float)$s['val'];
        $last_l_idx = $s['idx'];
    }
    if ($last_h !== null && $last_l !== null) break;
}
// --- [END SINKRONISASI] ---

$is_infoA1 = "";
$mfi_now = $mfi_results['today'] ?? 50;
$mfi_slope = $mfi_results['slope'] ?? 0;

// --- [BLOK 1: LOGIKA KHUSUS ALPHA_EXPLOSION_ENGINE - INDEPENDENT PATH] ---
try {
    // Ambil data dalam bentuk array
    $resA1 = checkInfoA1($prices, $highs, $lows);

    // MODIFIKASI GERBANG: Kita lepas pengunci market trending agar bot bisa mendeteksi VCP di akhir fase sideways
    if ($resA1) {
        // Definisikan ulang variabel agar logika di bawahnya tidak error
        $is_infoA1 = $resA1['type'];

        // --- TAMBAHAN KALKULASI ADX & OBV ---
        $adx_series = trader_adx($highs, $lows, $prices, 14);
        $current_adx = end($adx_series);

        $obv_series = trader_obv($prices, $volumes);
        $current_obv = end($obv_series);
        $prev_obv = (count($obv_series) >= 2) ? $obv_series[count($obv_series) - 2] : 0;

        // VETO 1: Filter Distribusi (MFI Slope)
        if ($mfi_slope < -2.0) {
            echo "LOG: [ALPHA_EXPLOSION] $ticker SKIP - Distribusi terdeteksi (MFI Slope: $mfi_slope)\n";
        }
        // --- TAMBAHAN VETO ADX & OBV ---
        elseif ($current_adx < 20 && $is_market_trending) {
            // Veto hanya berlaku jika fungsi HT mendeteksi trending tapi ADX-nya loyo
            echo "LOG: [ALPHA_EXPLOSION] $ticker SKIP - Tren ADX Lemah (" . round($current_adx, 2) . ")\n";
        } elseif ($current_obv <= $prev_obv) {
            echo "LOG: [ALPHA_EXPLOSION] $ticker SKIP - OBV Divergence (Tidak ada akumulasi volume).\n";
        }
        // --- END TAMBAHAN ---
        else {
            $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

            if ($explosion['is_vcp'] || $explosion['is_dry_out'] || $explosion['is_spike']) {

                // PERBAIKAN: Kirim $mfi_results ke isMomentumValid agar Police juga sinkron
                if (!isMomentumValid($rsi_history, $vol_for_scoring, null, null, null, $mfi_results)) {
                    echo "LOG: [ALPHA_EXPLOSION] $ticker REJECTED by Police. Momentum lemah/trap.\n";
                } else {
                    // HANYA MASUK SINI JIKA MOMENTUM & MFI VALID
                    $sql_a1 = "SELECT id FROM sent_infoa1 WHERE ticker = ? AND sent_at > NOW() - INTERVAL 18 HOUR";
                    $stmt_a1 = $conn->prepare($sql_a1);
                    $stmt_a1->bind_param("s", $ticker);
                    $stmt_a1->execute();
                    $res_db = $stmt_a1->get_result();

                    if ($res_db->num_rows == 0) {
                        // --- AMBIL DATA BANDARMOLOGI (Hanya jika lolos teknikal) ---
                        $clean_ticker = str_replace('.JK', '', $ticker);
                        $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);

                        // Modifikasi teks status penjelas internal agar lebih bernuansa Quants
                        $status_text = ($is_infoA1 === "SUPER_A1") ? "ALPHA BREAKOUT EX (STRONG)" : "LOW VOLATILITY COILING (ACCUM)";
                        $icon_a1 = ($is_infoA1 === "SUPER_A1") ? "⚡" : "🌀";
                        $rsi_val = round($rsi, 2);

                        // MODIFIKASI HEADER TELEGRAM
                        $msgA1 = "$icon_a1 <b>ENGINE: ALPHA EXPLOSION FIELD</b>\n";
                        $msgA1 .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                        $msgA1 .= "💎 STOCK : <b>$ticker</b>\n";
                        $msgA1 .= "💰 Price : <b>Rp " . number_format($current_price, 0, ',', '.') . "</b>\n";
                        $msgA1 .= "📊 Status: <b>$status_text</b>\n\n";

                        // --- TAMBAHAN INFORMASI TEKNIS + MFI ---
                        $msgA1 .= "🛠 <b>Technical Indicators:</b>\n";
                        $msgA1 .= "• ADX 14   : <code>" . round($current_adx, 2) . "</code>\n";
                        $msgA1 .= "• Money Fl : <code>" . round($mfi_now, 2) . "</code>\n";
                        $msgA1 .= "• OBV Stat : <code>" . ($current_obv > $prev_obv ? "ACCUM ✅" : "DIST ⚠️") . "</code>\n";
                        $msgA1 .= "• Market St: <code>" . ($is_market_trending ? "TRENDING STATE 🏎️" : "CYCLING/COILING ZONE 🧘") . "</code>\n";
                        $msgA1 .= "• SMA 50   : <code>" . number_format($resA1['sma50'], 0, ',', '.') . "</code>\n";
                        $msgA1 .= "• SMA 100  : <code>" . number_format($resA1['sma100'], 0, ',', '.') . "</code>\n\n";

                        $msgA1 .= "🎯 <b>Dynamic Levels:</b>\n";
                        $msgA1 .= "• Support    : <code>" . ($snr['support_1']['base'] ?? '-') . "</code> / <code>" . ($snr['support_2']['base'] ?? '-') . "</code>\n";
                        $msgA1 .= "• Resistance : <code>" . ($snr['resistance_1'] ?? '-') . "</code> / <code>" . ($snr['resistance_2'] ?? '-') . "</code>\n\n";

                        $msgA1 .= "🎯 <b>Explosion Radar:</b>\n";
                        if ($explosion['is_vcp'])     $msgA1 .= "• VCP Tightness (Penciutan) ✅\n";
                        if ($explosion['is_dry_out']) $msgA1 .= "• Volume Dry-out (Sepi) ✅\n";
                        if ($explosion['is_spike'])   $msgA1 .= "• Volume Spike (Pemanasan) ✅\n\n";

                        $msgA1 .= "⚡ <b>Kriteria Terpenuhi:</b>\n";
                        $msgA1 .= "• RSI Neutral : <code>$rsi_val</code>\n";
                        $msgA1 .= "• Vol Ratio  : <code>" . $vol_for_scoring . "x</code>\n\n";

                        if (is_array($analisis_bdm) && isset($analisis_bdm['top_buy']) && $analisis_bdm['top_buy'] !== '-') {
                            $msgA1 .= "👥 <b>Bandarmologi :</b> " . $analisis_bdm['kesimpulan'] . "\n";
                            $msgA1 .= "📥 <b>TOP BUY  :</b> <code>" . $analisis_bdm['top_buy'] . "</code>\n";
                            $msgA1 .= "📤 <b>TOP SELL :</b> <code>" . $analisis_bdm['top_sell'] . "</code>\n\n" . $analisis_bdm['footer_ritel'] . "\n\n";
                        }
                        $msgA1 .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n\n";
                        $msgA1 .= "📡 Source: " . VPS_ID;
                        $msgA1 .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                        if (sendTelegram($msgA1)) {
                            $ins_a1 = "INSERT INTO sent_infoa1 (ticker) VALUES (?)";
                            $stmt_ins = $conn->prepare($ins_a1);
                            $stmt_ins->bind_param("s", $ticker);
                            $stmt_ins->execute();
                            $stmt_ins->close();

                            $booster_type = $explosion['is_vcp'] ? "VCP" : ($explosion['is_dry_out'] ? "VDO" : "SPIKE");
                            recordNewSignal($conn, $ticker, $current_price, "ALPHA_EXPLOSION_$booster_type", round($rsi, 2), $k_val, $vol_for_scoring);

                            echo "LOG: [ALPHA_EXPLOSION] Sinyal $ticker terverifikasi VALIDATOR ledakan. Terkirim.\n";
                        }
                    }
                    $stmt_a1->close();
                } // END ELSE MOMENTUM
            } else {
                echo "LOG: [ALPHA_EXPLOSION] $ticker diabaikan. Akumulasi ada tapi belum matang (No Explosion Potential).\n";
            }
        } // END ELSE MFI SLOPE
    }
} catch (Throwable $e) {
    echo "⚠️ WARNING: Alpha Explosion bermasalah pada $ticker: " . $e->getMessage() . " (Line: " . $e->getLine() . ")\n";
}

// --- [BLOK 1b: LOGIKA VDO - ADAPTIVE SIKLUS BULL READY] ---
try {
    // 1. Panggil fungsi dan simpan hasilnya ke variabel $resVDO
    $resVDO = checkBullReady($prices, $highs, $lows, $volumes);

    // Cek apakah hasilnya array (sinyal valid) dan market sedang Sideways
    if ($resVDO !== false && !$is_market_trending) {

        // --- VALIDATOR KUALITAS (BOOSTER) ---
        $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

        // Bungkus kriteria ledakan agar Semuanya tunduk pada filter Sideways
        if (!$is_market_trending && ($explosion['is_vcp'] || $explosion['is_dry_out'] || $explosion['is_spike'])) {

            // --- VETO MFI: Harus Ada Aliran Uang Masuk ---
            if ($mfi_now < 45) {
                echo "LOG: [VDO] $ticker SKIP - Uang tidak mendukung (MFI: " . round($mfi_now, 2) . ")\n";
            } else {

                // --- SUNTIKAN INDIKATOR KASTA TINGGI: Ambil Siklus Fase Real-Time ---
                $dcphase_series = trader_ht_dcphase($prices);
                $current_phase = ($dcphase_series !== false) ? end($dcphase_series) : 0;

                // Filter Kuantitatif: Mengunci siklus di fase akumulasi akhir (270 - 360 derajat)
                // Kita beri toleransi longgar di 260 derajat untuk menangkap awal akumulasi
                $is_vdo_phase_valid = (
                    ($current_phase >= 260.0 && $current_phase <= 360.0)
                    ||
                    ($current_phase >= 0.0   && $current_phase <= 10.0)
                );

                // --- VETO POLICE: Kirim Data MFI untuk Audit Lengkap ---
                if (!isMomentumValid($rsi_history, $vol_for_scoring, $stoch, null, null, $mfi_results)) {
                    echo "LOG: [VDO] $ticker REJECTED by Police. Momentum lemah/trap.\n";
                } elseif (!$is_vdo_phase_valid) {
                    echo "LOG: [VDO] $ticker REJECTED - Masuk area sepi tapi jam biologis siklus belum matang (" . round($current_phase, 1) . "°).\n";
                } else {
                    // 2. Cek Anti-Spam
                    $sql_vdo = "SELECT id FROM sent_vdo WHERE ticker = ? AND sent_at > NOW() - INTERVAL 18 HOUR";
                    $stmt_vdo = $conn->prepare($sql_vdo);
                    $stmt_vdo->bind_param("s", $ticker);
                    $stmt_vdo->execute();
                    $db_check = $stmt_vdo->get_result();

                    if ($db_check->num_rows == 0) {
                        $clean_ticker = str_replace('.JK', '', $ticker);
                        $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);

                        $rsi_val = $resVDO['rsi'] ?? 0;
                        $tightness = $resVDO['range_pct'] ?? 0;

                        $v_labels = [];
                        if ($explosion['is_vcp'])     $v_labels[] = "VCP Tightness ✅";
                        if ($explosion['is_dry_out']) $v_labels[] = "Dry-out ✅";
                        if ($explosion['is_spike'])   $v_labels[] = "Vol Spike ✅";
                        $final_validation = implode("\n• ", $v_labels);

                        // --- STRUKTUR PESAN TELEGRAM ---
                        $msgVDO = "🚀 <b>BULL READY: VDO DETECTED</b>\n";
                        $msgVDO .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                        $msgVDO .= "💎 STOCK : <b>$ticker</b>\n";
                        $msgVDO .= "💰 Price : <b>Rp " . number_format($current_price, 0, ',', '.') . "</b>\n";
                        $msgVDO .= "📉 Status: <b>Volatility Dry-Out Maturing 🧭</b>\n\n";

                        $msgVDO .= "🛠 <b>Technical & Cycle Metrics:</b>\n";
                        $msgVDO .= "• Vol Ratio : <code>{$vol_for_scoring}x</code>\n";
                        $msgVDO .= "• Tightness : <code>{$tightness}%</code>\n";
                        // Menampilkan data jam siklus bursa ke Telegram Anda
                        $msgVDO .= "• Cycle Clk : <code>" . round($current_phase, 1) . "° / 360° (Kuadrant IV)</code>\n";
                        $msgVDO .= "• Money Fl  : <code>" . round($mfi_now, 2) . "</code>\n\n";

                        $msgVDO .= "🎯 <b>Dynamic Levels:</b>\n";
                        $msgVDO .= "• Support    : <code>" . ($snr['support_1']['base'] ?? '-') . "</code>\n";
                        $msgVDO .= "• Resistance : <code>" . ($snr['resistance_1'] ?? '-') . "</code>\n\n";

                        $msgVDO .= "🎯 <b>Validator:</b>\n";
                        $msgVDO .= "• " . $final_validation . "\n\n";

                        if (is_array($analisis_bdm) && isset($analisis_bdm['top_buy']) && $analisis_bdm['top_buy'] !== '-') {
                            $msgVDO .= "👥 <b>Bandarmologi :</b> " . ($analisis_bdm['kesimpulan'] ?? 'N/A') . "\n";
                            $msgVDO .= "📥 <b>TOP BUY  :</b> <code>" . $analisis_bdm['top_buy'] . "</code>\n";
                            $msgVDO .= "📤 <b>TOP SELL :</b> <code>" . $analisis_bdm['top_sell'] . "</code>\n\n" . ($analisis_bdm['footer_ritel'] ?? '') . "\n\n";
                        }

                        $msgVDO .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n\n";
                        $msgVDO .= "📡 Source: " . VPS_ID;
                        $msgVDO .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                        if (sendTelegram($msgVDO)) {
                            // 3. Simpan ke Tracker
                            recordNewSignal($conn, $ticker, $current_price, "VDO_VALIDATED_WITH_PHASE", $rsi_val, $k_val, $vol_for_scoring);

                            $ins_vdo = "INSERT INTO sent_vdo (ticker) VALUES (?)";
                            $stmt_ins_vdo = $conn->prepare($ins_vdo);
                            $stmt_ins_vdo->bind_param("s", $ticker);
                            $stmt_ins_vdo->execute();
                            $stmt_ins_vdo->close();

                            echo "LOG: [VDO-VALIDATED] Sinyal $ticker terkirim.\n";
                        }
                    }
                    $stmt_vdo->close();
                } // END ELSE POLICE & PHASE
            } // END ELSE MFI
        } else {
            echo "LOG: [VDO-REJECT] $ticker struktur belum matang atau market trending.\n";
        }
    }
} catch (Throwable $e) {
    echo "⚠️ WARNING: VDO logic error pada $ticker: " . $e->getMessage() . " (Line: " . $e->getLine() . ")\n";
}


// --- [BLOK 1c: STRATEGI RUBBER BAND - ADAPTIVE SIKLUS REBOUND] ---
try {
    // 1. Panggil fungsi deteksi utama
    $rubber = checkRubberBand($prices, $highs, $lows, $volumes, $rsi_history);

    // --- SUNTIKAN INDIKATOR KASTA TINGGI: Ambil Arloji Siklus Pasar Real-Time ---
    $dcphase_series = trader_ht_dcphase($prices);
    $current_phase = ($dcphase_series !== false) ? end($dcphase_series) : 0;

    // Aturan Kuantitatif: Siklus pembalikan dasar/rebound paling kuat berada di rentang 260° - 315°
    $is_rebound_cycle_mature = ($current_phase >= 260.0 && $current_phase <= 320.0);

    // --- ESTIMASI MEAN REVERSION TIME ---
    $h_anchor = $last_h_idx ?? ($total_candle - 10);
    $stretch_duration = abs($total_candle - $h_anchor);
    $is_reversal_time = in_array($stretch_duration, [5, 8, 13]);

    $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

    // Kita tambahkan filter pengunci fase kuantitatif ($is_rebound_cycle_mature)
    if ($rubber && $explosion['is_spike'] && $is_rebound_cycle_mature) {

        // FILTER POLICE: Pastikan momentum sudah mulai berbalik arah (Hook Up)
        if (!isMomentumValid($rsi_history, $vol_for_scoring, $stoch)) {
            echo "LOG: [RUBBER] $ticker REJECTED by Police. Harga masih terjun bebas.\n";
        } else {
            // 2. Cek Anti-Spam (Interval 24 Jam)
            $sql_rb = "SELECT id FROM sent_rubber WHERE ticker = ? AND sent_at > NOW() - INTERVAL 24 HOUR";
            $stmt_rb = $conn->prepare($sql_rb);
            $stmt_rb->bind_param("s", $ticker);
            $stmt_rb->execute();
            $res_db = $stmt_rb->get_result();

            if ($res_db->num_rows == 0) {
                $climax_icon = $rubber['is_climax'] ? "💎 (Volume Climax Detected!)" : "⚠️ (Wait for Volume Confirmation)";

                $msgRB = "🪃 <b>STRATEGY: RUBBER BAND REBOUND</b>\n";
                $msgRB .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                $msgRB .= "💎 STOCK : <b>$ticker</b>\n";
                $msgRB .= "🕒 Time Duration : <code>" . $stretch_duration . " Bars " . ($is_reversal_time ? "(Mature ✅)" : "") . "</code>\n";
                $msgRB .= "🧭 Cycle Clock  : <code>" . round($current_phase, 1) . "° / 360° (Turning Point)</code>\n"; // Menampilkan data fase ke Telegram
                $msgRB .= "📊 Gap SMA20     : <code>" . $rubber['gap'] . "%</code>\n";
                $msgRB .= "📉 RSI Value     : <code>" . $rubber['rsi'] . " (Hook Up)</code>\n";
                $msgRB .= "🔊 Volume        : $climax_icon\n\n";
                $msgRB .= "💡 <i>Target Rebound: " . $rubber['sma20'] . "</i>\n\n";

                $msgRB .= "🎯 <b>Dynamic Levels (Safety Floor):</b>\n";
                $msgRB .= "• Support    : <code>" . ($snr['support_1']['base'] ?? '-') . "</code>\n";
                $msgRB .= "• Resistance : <code>" . ($snr['resistance_1'] ?? '-') . "</code>\n\n";

                $msgRB .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n\n";
                $msgRB .= "📡 Source: " . VPS_ID;
                $msgRB .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                if (sendTelegram($msgRB)) {
                    recordNewSignal($conn, $ticker, $current_price, "RUBBER_BAND_ADAPTIVE_REBOUND", $rubber['rsi'], $k_val, $vol_for_scoring);
                    $conn->query("INSERT INTO sent_rubber (ticker) VALUES ('$ticker')");
                    echo "LOG: [RUBBER-S SIKLUS] Sinyal $ticker terverifikasi & terkirim.\n";
                }
            }
            $stmt_rb->close();
        }
    } else {
        if (!$is_rebound_cycle_mature && $rubber && $explosion['is_spike']) {
            echo "LOG: [RUBBER] $ticker REJECTED - Gap lebar terpenuhi, tapi posisi siklus HT belum matang (" . round($current_phase, 1) . "°).\n";
        }
    }
} catch (Throwable $e) {
    echo "⚠️ RUBBER Error pada $ticker: " . $e->getMessage() . "\n";
}


// --- [BLOK 1e: STRATEGI WYCKOFF SPRING - FALSE BREAKDOWN ADAPTIVE] ---
try {
    // ASURANSI DATA: Menghilangkan peringatan kuning "Possible undefined variable"
    if (!isset($snr) || !isset($snr['support_1'])) {
        if (function_exists('getDynamicZones')) {
            $swings_temp = getSwings($prices);
            $snr = getDynamicZones($prices, $swings_temp['highs'], $swings_temp['lows'], $highs, $lows);
        }
    }

    // Ambil data Support Riil
    $real_support = $snr['support_1']['base'] ?? null;

    if ($real_support !== null) {
        $spring = checkWyckoffSpring($prices, $lows, $volumes, $rsi_history);

        // --- ADDED: TIME CYCLE FOR SPRING ---
        $time_from_peak = abs($total_candle - $last_h_idx);
        $fib_time_seq = [5, 8, 13, 21, 34];
        $is_time_mature = false;
        foreach ($fib_time_seq as $ft) {
            if (abs($time_from_peak - $ft) <= 1) {
                $is_time_mature = true;
                break;
            }
        }
        // --- END TIME CYCLE ---

        // --- SUNTIKAN INDIKATOR KASTA TINGGI: Ambil Arloji Fase Siklus Real-Time ---
        $dcphase_series = trader_ht_dcphase($prices);
        $current_phase = ($dcphase_series !== false) ? end($dcphase_series) : 0;

        // Filter Kuantitatif: Mengunci siklus di fase Re-akumulasi Kritis (265 - 330 derajat)
        $is_spring_phase_valid = ($current_phase >= 265.0 && $current_phase <= 330.0);

        $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

        // Kita kunci gerbang utama dengan filter fase kuantitatif ($is_spring_phase_valid)
        if ($spring && ($explosion['is_spike'] || $explosion['is_dry_out']) && $is_spring_phase_valid) {

            // Filter Kualitas (ATR Adaptive)
            $min_trap_distance = $current_atr_20d * 0.5;
            $actual_trap_distance = $real_support - $spring['trap_low'];

            $is_valid_depth = ($actual_trap_distance >= $min_trap_distance);
            $has_reclaimed = ($current_price > $real_support);
            $trap_depth_pct = ($actual_trap_distance / $real_support) * 100;

            if ($is_valid_depth && $trap_depth_pct >= 0.8 && $has_reclaimed && isMomentumValid($rsi_history, $vol_for_scoring, $stoch, $current_atr_20d, $price_audit, $mfi_results)) {

                $sql_sp = "SELECT id FROM sent_spring WHERE ticker = ? AND sent_at > NOW() - INTERVAL 24 HOUR";
                $stmt_sp = $conn->prepare($sql_sp);
                $stmt_sp->bind_param("s", $ticker);
                $stmt_sp->execute();

                if ($stmt_sp->get_result()->num_rows == 0) {
                    $clean_ticker = str_replace('.JK', '', $ticker);
                    $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);
                    $is_distribusi_masif = (strpos($analisis_bdm['kesimpulan'], 'DISTRIBUTION') !== false);

                    if (!$is_distribusi_masif || $vol_for_scoring > 2.0) {
                        $msgSP = "🪤 <b>STRATEGY: WYCKOFF SPRING</b>\n";
                        $msgSP .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                        $msgSP .= "💎 STOCK : <b>$ticker</b>\n\n";

                        $msgSP .= "📊 <b>Data Teknis & Fase Kuantitatif:</b>\n";
                        $msgSP .= "⚠️ Status: <b>Bear Trap " . ($is_time_mature ? "(Time Mature ✅)" : "(Fast Trap)") . "</b>\n";
                        $msgSP .= "• Real Support  : <code>" . $real_support . "</code>\n";
                        $msgSP .= "• Trap Low       : <code>" . $spring['trap_low'] . "</code>\n";
                        $msgSP .= "• Cycle Clock    : <code>" . round($current_phase, 1) . "° / 360° (Spring Stage)</code>\n"; // Menampilkan data fase ke Telegram
                        $msgSP .= "• Dist. to ATR   : <code>" . round($actual_trap_distance / $current_atr_20d, 2) . "x ATR</code>\n";
                        $msgSP .= "• RSI Status     : <code>" . $spring['rsi_val'] . " (Uptick)</code>\n";
                        $msgSP .= "• Vol Power      : <code>{$vol_for_scoring}x</code>\n\n";

                        $msgSP .= "🎯 <b>Confirmation Levels:</b>\n";
                        $msgSP .= "• S1 Area       : <code>" . ($snr['support_1']['base'] ?? '-') . "</code>\n";
                        $msgSP .= "• Target Res    : <code>" . ($snr['resistance_1'] ?? '-') . "</code>\n\n";

                        if (is_array($analisis_bdm) && isset($analisis_bdm['top_buy'])) {
                            $msgSP .= "👥 <b>Bandarmologi :</b> " . $analisis_bdm['kesimpulan'] . "\n";
                            $msgSP .= "📥 <b>TOP BUY  :</b> <code>" . $analisis_bdm['top_buy'] . "</code>\n\n";
                        }

                        $msgSP .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n";
                        $msgSP .= "📡 Source: " . VPS_ID . "\n";
                        $msgSP .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                        if (sendTelegram($msgSP)) {
                            recordNewSignal($conn, $ticker, $current_price, "WYCKOFF_SPRING_ADAPTIVE_TRAP", $spring['rsi_val'], $k_val, $vol_for_scoring);
                            $conn->query("INSERT INTO sent_spring (ticker) VALUES ('$ticker')");
                            echo "LOG: [SPRING-REAL-PHASE] Sinyal $ticker terkonfirmasi via Siklus HT.\n";
                        }
                    }
                }
                $stmt_sp->close();
            } else {
                $reason = (!$is_valid_depth) ? "Jarak trap < 0.5x ATR" : (!$has_reclaimed ? "Belum Reclaim" : "Momentum lemah");
                echo "LOG: [SPRING] $ticker REJECTED. $reason.\n";
            }
        } else {
            if (!$is_spring_phase_valid && $spring && ($explosion['is_spike'] || $explosion['is_dry_out'])) {
                echo "LOG: [SPRING] $ticker REJECTED - Pola terdeteksi, tapi posisi fase HT belum matang (" . round($current_phase, 1) . "°).\n";
            }
        }
    } else {
        echo "LOG: [SPRING] $ticker SKIP - Tidak ditemukan support riil.\n";
    }
} catch (Throwable $e) {
    echo "⚠️ SPRING Error pada $ticker: " . $e->getMessage() . "\n";
}

// --- [BLOK 1f: STRATEGI BLUE SKY BREAKOUT - MOMENTUM RALLY] ---
// --- [BLOK 1f: STRATEGI BLUE SKY BREAKOUT - ADAPTIVE MOMENTUM RALLY] ---
try {
    // 1. Ambil data Resistance dari SNR Analysis (Fractal)
    $res1 = $snr['resistance_1'] ?? null;
    $res2 = $snr['resistance_2'] ?? null;

    $sky = checkBlueSky($prices, $highs, $volumes);
    $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

    // 2. LOGIKA FILTER STRUKTUR BERLAPIS (Hard Filter)
    $is_pass_res1 = ($res1 === null || $current_price > $res1);
    $is_pass_res2 = ($res2 === null || $current_price > $res2);

    if ($sky && $is_market_trending && $explosion['is_spike']) {

        // --- EVALUASI PENGAMAN STRUKTUR ---
        if (!$is_pass_res1 || !$is_pass_res2) {
            $blocked_by = (!$is_pass_res1) ? "R1 ($res1)" : "R2 ($res2)";
            echo "LOG: [BLUESKY] $ticker REJECTED - Masih tertahan Resistance Riil $blocked_by.\n";
        }
        // Filter Police: Standar keamanan momentum dan overbought
        elseif (!isMomentumValid($rsi_history, $vol_for_scoring, $stoch, $current_atr_20d, $price_audit, $mfi_results) || $rsi > 80) {
            echo "LOG: [BLUESKY] $ticker REJECTED by Police. Terlalu pucuk/lelah.\n";
        } else {

            // 3. EKSEKUSI INDIKATOR KASTA TINGGI: Kaufman Adaptive Moving Average (KAMA)
            // Kita pasang periode 10 untuk mendeteksi batas aman "Trailing Stop Kuantitatif"
            $kama_series = trader_kama($prices, 10);
            $current_kama = ($kama_series !== false) ? end($kama_series) : $current_price * 0.95;

            // --- JIKA LOLOS SEMUA FILTER, BARU KIRIM KE TELEGRAM & TRACKER ---
            $sql_sky = "SELECT id FROM sent_bluesky WHERE ticker = ? AND sent_at > NOW() - INTERVAL 24 HOUR";
            $stmt_sky = $conn->prepare($sql_sky);
            $stmt_sky->bind_param("s", $ticker);
            $stmt_sky->execute();

            if ($stmt_sky->get_result()->num_rows == 0) {
                $target_label = ($res1 === null && $res2 === null) ? "UNCHARTED TERRITORY (BLUE SKY) 🌌" : "NEXT TARGET CLEAR ✅";

                $msgSky = "🌌 <b>STRATEGY: BLUE SKY BREAKOUT</b>\n";
                $msgSky .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                $msgSky .= "💎 STOCK : <b>$ticker</b>\n";
                $msgSky .= "🚀 Status: <b>$target_label</b>\n\n";

                $msgSky .= "📊 <b>Data Transaksi & KAMA Audit:</b>\n";
                $msgSky .= "• Breakout Level : <code>" . $sky['breakout_price'] . "</code>\n";
                $msgSky .= "• Vol Increase   : <code>" . $vol_for_scoring . "x</code>\n";
                $msgSky .= "• Adaptive Floor : <code>" . round($current_kama, 0) . " (KAMA 10)</code>\n";
                $msgSky .= "• RSI Status     : <code>" . round($rsi, 2) . "</code>\n\n";

                $msgSky .= "🎯 <b>Dynamic Levels & Protection:</b>\n";
                $msgSky .= "• Support 1      : <code>" . ($snr['support_1']['base'] ?? '-') . "</code>\n";
                $msgSky .= "• Next Barrier   : <code>" . ($res1 ?? 'NONE (FLY HIGH)') . "</code>\n";
                $msgSky .= "• Trailing Stop  : <b>Rp " . number_format($current_kama, 0, ',', '.') . " 🛡️</b>\n\n";

                $msgSky .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n";
                $msgSky .= "📡 Source: " . VPS_ID . "\n";
                $msgSky .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                if (sendTelegram($msgSky)) {
                    recordNewSignal($conn, $ticker, $current_price, "BLUE_SKY_ADAPTIVE_BREAK", round($rsi, 2), $k_val, $vol_for_scoring);
                    $conn->query("INSERT INTO sent_bluesky (ticker) VALUES ('$ticker')");
                    echo "LOG: [BLUESKY-KAMA] Sinyal $ticker Valid & Terkirim.\n";
                }
            }
            $stmt_sky->close();
        }
    }
} catch (Throwable $e) {
    echo "⚠️ BLUESKY Adaptive Error pada $ticker: " . $e->getMessage() . "\n";
}

// --- [BLOK 1g: STRATEGI GOLDEN SQUEEZE - SIKLUS VOLATILITY COILING] ---
try {
    $squeeze = checkGoldenSqueeze($prices, $highs, $lows);

    $l_anchor = $last_l_idx ?? ($total_candle - 10);
    $squeeze_age = abs($total_candle - $l_anchor);
    $is_super_squeeze = ($squeeze_age >= 21);

    // EKSEKUSI INDIKATOR KASTA TINGGI: Hilbert Transform - Sine Wave
    $ht_sine_series = trader_ht_sine($prices);

    $is_sine_cycle_valid = false;
    $sine_val = 0;
    $lead_sine_val = 0;

    if ($ht_sine_series !== false && isset($ht_sine_series[0], $ht_sine_series[1])) {
        $sine_array      = $ht_sine_series[0];
        $lead_sine_array = $ht_sine_series[1];

        $sine_val      = end($sine_array);
        $lead_sine_val = end($lead_sine_array);

        // Ambil data satu bar sebelumnya untuk deteksi crossover (Golden Cross Siklus)
        $prev_sine      = $sine_array[count($sine_array) - 2] ?? 0;
        $prev_lead_sine = $lead_sine_array[count($lead_sine_array) - 2] ?? 0;

        // Aturan Kuantitatif: Sine memotong ke atas Lead-Sine (Sinyal Leading Reversal)
        // Atau keduanya mengarah ke atas di area dasar siklus
        if (($prev_sine <= $prev_lead_sine && $sine_val > $lead_sine_val) || ($sine_val > $lead_sine_val && $sine_val > $prev_sine)) {
            $is_sine_cycle_valid = true;
        }
    }

    $explosion = checkExplosionPotential($highs, $lows, $volumes, $prices);

    // Filter diperketat: Squeeze wajib didukung Siklus Hilbert Transform
    if ($squeeze && !$is_market_trending && ($explosion['is_dry_out'] || $explosion['is_spike']) && $is_sine_cycle_valid) {

        // VETO MFI: Pastikan ada aliran uang masuk (> 50)
        if ($mfi_now < 50) {
            echo "LOG: [SQUEEZE] $ticker REJECTED - Money Flow lemah ($mfi_now)\n";
        } else {
            if (!isMomentumValid($rsi_history, $vol_for_scoring, $stoch, null, null, $mfi_results)) {
                echo "LOG: [SQUEEZE] $ticker REJECTED by Police.\n";
            } else {
                $sql_sq = "SELECT id FROM sent_squeeze WHERE ticker = ? AND sent_at > NOW() - INTERVAL 48 HOUR";
                $stmt_sq = $conn->prepare($sql_sq);
                $stmt_sq->bind_param("s", $ticker);
                $stmt_sq->execute();
                $res_db = $stmt_sq->get_result();

                if ($res_db->num_rows == 0) {
                    $clean_ticker = str_replace('.JK', '', $ticker);
                    $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);

                    $msgSQ = "🌀 <b>STRATEGY: GOLDEN SQUEEZE DETECTED</b>\n";
                    $msgSQ .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                    $msgSQ .= "💎 STOCK : <b>$ticker</b>\n\n";

                    $msgSQ .= "📊 <b>Technical Setup & Cycle:</b>\n";
                    $msgSQ .= "⚠️ Status: <b>Volatility Coiling " . ($is_super_squeeze ? "(Super Squeeze 🔥)" : "") . "</b>\n";
                    $msgSQ .= "⏳ Squeeze Age: <code>" . $squeeze_age . " Bars</code>\n";
                    $msgSQ .= "• HT Sine Line : <code>" . round($sine_val, 2) . " / " . round($lead_sine_val, 2) . " (Triggered)</code>\n";
                    $msgSQ .= "• Tightness   : <code>" . $squeeze['squeeze_tightness'] . "%</code>\n";
                    $msgSQ .= "• Money Flow  : <code>" . round($mfi_now, 2) . "</code>\n\n";

                    $msgSQ .= "🎯 <b>Dynamic Levels:</b>\n";
                    $msgSQ .= "• Support    : <code>" . ($snr['support_1']['base'] ?? '-') . "</code>\n";
                    $msgSQ .= "• Resistance : <code>" . ($snr['resistance_1'] ?? '-') . "</code> / <code>" . ($snr['resistance_2'] ?? '-') . "</code>\n\n";

                    if (is_array($analisis_bdm) && isset($analisis_bdm['top_buy']) && $analisis_bdm['top_buy'] !== '-') {
                        $msgSQ .= "👥 <b>Bandarmologi :</b> " . $analisis_bdm['kesimpulan'] . "\n";
                        $msgSQ .= "📥 <b>TOP BUY  :</b> <code>" . $analisis_bdm['top_buy'] . "</code>\n";
                        $msgSQ .= "📤 <b>TOP SELL :</b> <code>" . $analisis_bdm['top_sell'] . "</code>\n\n" . $analisis_bdm['footer_ritel'] . "\n\n";
                    }

                    $msgSQ .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n";
                    $msgSQ .= "📡 Source: " . VPS_ID . "\n";
                    $msgSQ .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

                    if (sendTelegram($msgSQ)) {
                        recordNewSignal($conn, $ticker, $current_price, "GOLDEN_SQUEEZE_CYCLE_VALID", round($rsi, 2), $k_val, $vol_for_scoring);
                        $conn->query("INSERT INTO sent_squeeze (ticker) VALUES ('$ticker')");
                        echo "LOG: [SQUEEZE-HILBERT] Sinyal $ticker Terkirim.\n";
                    }
                }
                $stmt_sq->close();
            } // END ELSE POLICE
        } // END ELSE MFI
    }
} catch (Throwable $e) {
    echo "⚠️ SQUEEZE Hilbert Error pada $ticker: " . $e->getMessage() . "\n";
}

// --- [BLOK 1i: STRATEGI SUPER TREND ALIGNMENT - ADAPTIVE PERFECT ORDER] ---
try {
    // 1. Ambil indikator bawaan dasar untuk pelengkap struktur
    $sma100_series = trader_sma($prices, 100);
    $sma100        = end($sma100_series);

    // 2. EKSEKUSI INDIKATOR KASTA TINGGI: MESA Adaptive Moving Average
    // Argumen default TA-Lib MAMA: Fast Limit (0.5), Slow Limit (0.05)
    $mama_fama_series = trader_mama($prices, 0.5, 0.05);

    if ($mama_fama_series !== false && isset($mama_fama_series[0], $mama_fama_series[1])) {
        $mama = end($mama_fama_series[0]); // Garis Adaptive Cepat
        $fama = end($mama_fama_series[1]); // Garis Pengawal Lambat

        // 3. Syarat Mutlak Baru: Perfect Adaptive Alignment
        // MAMA harus berada di atas FAMA, dan keduanya harus berada di atas SMA 100 (Major Trend)
        $is_adaptive_alignment = ($mama > $fama && $fama > $sma100);

        // 4. Syarat Momentum: Harga aman di atas MAMA
        $is_on_track = ($current_price > $mama);

        if ($is_market_trending && $is_adaptive_alignment && $is_on_track) {

            // --- ESTIMASI TIME CYCLE (DURASI TREN) ---
            $l_anchor = $last_l_idx ?? ($total_candle - 20);
            $trend_age = abs($total_candle - $l_anchor);

            $next_cycle = 21;
            foreach ([34, 55, 89, 144] as $f_time) {
                if ($trend_age < $f_time) {
                    $next_cycle = $f_time;
                    break;
                }
            }
            $remaining_bars = $next_cycle - $trend_age;

            // --- VALIDASI VISUAL & MOMENTUM ---
            $visual_bullish = isset($bullish_pattern) ? $bullish_pattern : false;
            $visual_neutral = isset($is_indecision) ? $is_indecision : false;
            $has_visual = ($visual_bullish !== false || $visual_neutral === true);

            // Eksekusi jika terjadi Stochastic GC di bawah ambang batas overbought
            if ($stoch['is_gc'] && $k_val < 50 && $has_visual) {

                if (!isMomentumValid($rsi_history, $vol_for_scoring, $stoch, $current_atr_20d, $price_audit, $mfi_results)) {
                    echo "LOG: [SUPER-TREND] $ticker REJECTED by Police.\n";
                } else {
                    // Anti Spam 20 Jam
                    $sql_tr = "SELECT id FROM sent_trendrider WHERE ticker = ? AND sent_at > NOW() - INTERVAL 20 HOUR";
                    $stmt_tr = $conn->prepare($sql_tr);
                    $stmt_tr->bind_param("s", $ticker);
                    $stmt_tr->execute();

                    if ($stmt_tr->get_result()->num_rows == 0) {
                        $clean_ticker = str_replace('.JK', '', $ticker);
                        $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);

                        $msgTR = "🌊 <b>STRATEGY: SUPER TREND RIDER</b>\n";
                        $msgTR .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                        $msgTR .= "💎 STOCK : <b>$ticker</b>\n";
                        $msgTR .= "💰 Price : <b>Rp " . number_format($current_price, 0, ',', '.') . "</b>\n";
                        $msgTR .= "📈 Status: <b>MESA Adaptive Perfect Alignment</b>\n\n";

                        $msgTR .= "🛠 <b>Trend Health:</b>\n";
                        $msgTR .= "• Structure : <code>MAMA > FAMA > SMA100 🔥</code>\n";
                        $msgTR .= "• Trend Age : <code>$trend_age Bars</code>\n";
                        $msgTR .= "• Est. Next : <code>Cycle $next_cycle (+ $remaining_bars Bars)</code>\n";
                        $msgTR .= "• Momentum  : <code>Stoch GC + Visual ✅</code>\n\n";

                        $msgTR .= "🎯 <b>Dynamic Adaptive Levels:</b>\n";
                        $msgTR .= "• Support MAMA (Fast): <code>" . round($mama, 0) . "</code>\n";
                        $msgTR .= "• Support FAMA (Slow): <code>" . round($fama, 0) . "</code>\n\n";

                        if (is_array($analisis_bdm) && isset($analisis_bdm['top_buy'])) {
                            $msgTR .= "👥 <b>Bandarmologi :</b> " . $analisis_bdm['kesimpulan'] . "\n";
                            $msgTR .= "📥 <b>TOP BUY  :</b> <code>" . $analisis_bdm['top_buy'] . "</code>\n\n";
                        }

                        $msgTR .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n";
                        $msgTR .= "📡 Source: " . VPS_ID;

                        if (sendTelegram($msgTR)) {
                            $conn->query("INSERT INTO sent_trendrider (ticker) VALUES ('$ticker')");
                            recordNewSignal($conn, $ticker, $current_price, "SUPER_TREND_ADAPTIVE_ALIGNMENT", round($rsi, 2), $k_val, $vol_for_scoring);
                            echo "LOG: [SUPER-TREND-MAMA] Sinyal $ticker Terkirim.\n";
                        }
                    }
                    $stmt_tr->close();
                }
            }
        }
    } else {
        echo "LOG: [SUPER-TREND] $ticker Skip - Fungsi trader_mama gagal memproses data.\n";
    }
} catch (Throwable $e) {
    echo "⚠️ SUPER-TREND MAMA Error: " . $e->getMessage() . " (Line: " . $e->getLine() . ")\n";
}

// --- [BLOK 1j: STRATEGI GOLDEN FIBO AREA REBOUND - ADAPTIVE PRICE, TIME & PHASE] ---
try {
    $clean_swings = getCleanSwings($highs, $lows);
    $reversed_swings = array_reverse($clean_swings);
    $total_candle = count($prices);
    $min_idx = ($total_candle - 1) - 4; // Konfirmasi fractal 4 candle matang

    $last_h = null;
    $last_h_idx = null;
    $last_l = null;
    $last_l_idx = null;

    foreach ($reversed_swings as $s) {
        if ($s['idx'] > $min_idx) continue;
        if ($s['type'] === 'H' && $last_h === null) {
            $last_h = (float)$s['val'];
            $last_h_idx = $s['idx'];
        }
        if ($s['type'] === 'L' && $last_l === null) {
            $last_l = (float)$s['val'];
            $last_l_idx = $s['idx'];
        }
        if ($last_h !== null && $last_l !== null) break;
    }

    if ($is_market_trending && $last_h !== null && $last_l !== null && $last_h > $last_l) {
        $swing_range = $last_h - $last_l;

        $atr_series = trader_atr($highs, $lows, $prices, 14);
        $current_atr = is_array($atr_series) ? end($atr_series) : ($current_price * 0.02);

        // --- 1. ADAPTIVE ATR MULTIPLIER ---
        $price_now = end($prices);
        $atr_mult = ($price_now < 500) ? 10 : (($price_now < 2000) ? 7 : 5);

        if ($swing_range >= ($current_atr * $atr_mult)) {
            $fib_618 = $last_h - ($swing_range * 0.618);

            // --- 2. DEFINISI AREA (BUFFER 0.8%) ---
            $area_upper = $fib_618 * 1.008;
            $area_lower = $fib_618 * 0.992;

            $is_in_fibo_area = ($current_price >= $area_lower && $current_price <= $area_upper);
            $s1_base = $snr['support_1']['base'] ?? 0;
            $is_confluence = ($s1_base > 0 && abs($fib_618 - $s1_base) / $s1_base < 0.02);

            if ($is_in_fibo_area && $is_confluence) {

                // --- 3. TIME CYCLE ANALYSIS (FIBONACCI SEQUENCE) ---
                $time_duration = abs($last_h_idx - $last_l_idx);
                $fib_time_seq = [3, 5, 8, 13, 21, 34];
                $is_time_mature = false;
                foreach ($fib_time_seq as $ft) {
                    if (abs($time_duration - $ft) <= 1) {
                        $is_time_mature = true;
                        break;
                    }
                }

                // --- 3b. EKSEKUSI INDIKATOR KASTA TINGGI: Hilbert Transform - Dominant Cycle Phase ---
                $dcphase_series = trader_ht_dcphase($prices);
                $current_phase = ($dcphase_series !== false) ? end($dcphase_series) : 0;

                // Filter Kuantitatif Fase Dasar Siklus (Batas Akumulasi Rebound Matang: 250 - 315 derajat)
                $is_phase_correct = ($current_phase >= 245.0 && $current_phase <= 320.0);

                // --- 4. PREMIUM MOMENTUM & VISUAL AUDIT ---
                $adx_slope = $adx_data['adx'] - $adx_data['prev_adx'];
                $is_selling_exhausted = ($adx_data['adx'] > 25 && $adx_slope < -0.3);
                $is_money_entering = ($mfi_now > 20 && $mfi_slope > 0.5);
                $is_momentum_turning = ($rsi > 30 && $stoch['is_gc'] && $k_val > 15);

                $visual_bullish = isset($bullish_pattern) ? $bullish_pattern : false;
                $visual_neutral = isset($is_indecision) ? $is_indecision : false;
                $has_visual = ($visual_bullish !== false || $visual_neutral === true);

                // Tambahkan validasi fase ke dalam gerbang eksekusi final
                if ($is_selling_exhausted && $is_money_entering && $is_momentum_turning && $has_visual && $is_phase_correct) {

                    if (isMomentumValid($rsi_history, $vol_for_scoring, $stoch, null, null, $mfi_results)) {

                        $sql_fib  = "SELECT id FROM signal_logs WHERE ticker = ? AND created_at > NOW() - INTERVAL 24 HOUR AND rsi_value = 666";
                        $stmt_fib = $conn->prepare($sql_fib);
                        $stmt_fib->bind_param("s", $ticker);
                        $stmt_fib->execute();

                        if ($stmt_fib->get_result()->num_rows == 0) {
                            $msgFib  = "📏 <b>STRATEGY: FIBONACCI GOLDEN AREA</b>\n";
                            $msgFib .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
                            $msgFib .= "💎 STOCK : <b>$ticker</b>\n";
                            $msgFib .= "🚀 Status: <b>Price, Time & Phase Confluence 🧭</b>\n";
                            $msgFib .= "📏 Power  : <code>" . round($swing_range / $current_atr, 1) . "x ATR</code>\n";
                            $msgFib .= "🕒 Time   : <code>" . $time_duration . " Bars " . ($is_time_mature ? "(Mature ✅)" : "") . "</code>\n";
                            $msgFib .= "🧭 Phase  : <code>" . round($current_phase, 1) . "° (Accumulation Base Area)</code>\n\n";

                            $msgFib .= "📊 <b>Zone Details:</b>\n";
                            $msgFib .= "• Area Top   : <code>" . number_format($area_upper, 0) . "</code>\n";
                            $msgFib .= "• Fib 61.8%  : <code>" . number_format($fib_618, 0) . "</code>\n";
                            $msgFib .= "• Area Bottom: <code>" . number_format($area_lower, 0) . "</code>\n";
                            $msgFib .= "• Price Now  : <b>Rp " . number_format($current_price, 0) . " ✅</b>\n\n";

                            $msgFib .= "🚀 <b>Advanced Engine Verification:</b>\n";
                            $msgFib .= "• Cycle Clock: <code>" . round($current_phase, 1) . "° / 360° Maturing ✅</code>\n";
                            $msgFib .= "• Money Flow : <code>Accumulating ✅</code>\n";
                            $msgFib .= "• Turning    : <code>UP Uptick ✅</code>\n\n";

                            $msgFib .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n";
                            $msgFib .= "📡 Source: " . VPS_ID;

                            if (sendTelegram($msgFib)) {
                                $conn->query("INSERT INTO signal_logs (ticker, rsi_value) VALUES ('$ticker', 666)");
                                recordNewSignal($conn, $ticker, $current_price, "FIBO_CYCLE_PHASE_SINKRON", round($rsi, 2), $k_val, $vol_for_scoring);
                            }
                        }

                        // ✅ PATCH #5: close() sekarang berada dalam scope yang sama dengan prepare()
                        //    Sebelumnya posisinya di luar blok ini, menyebabkan fatal error
                        //    saat $stmt_fib tidak terdefinisi karena kondisi confluence tidak terpenuhi.
                        $stmt_fib->close();
                    } // end isMomentumValid

                } else {
                    // Log jika fase belum matang (tidak berubah dari aslinya)
                    if (!$is_phase_correct && $is_in_fibo_area && $is_confluence) {
                        echo "LOG: [FIBO] $ticker REJECTED - Harga masuk area tapi Jam Siklus HT belum matang (" . round($current_phase, 1) . "°).\n";
                    }
                    // ✅ Tidak ada $stmt_fib di sini karena belum pernah di-prepare — aman.
                }
            }
        }
    }
} catch (Throwable $e) {
    echo "⚠️ FIBO Adaptive Phase Error pada $ticker: " . $e->getMessage() . " (Line: " . $e->getLine() . ")\n";
}
