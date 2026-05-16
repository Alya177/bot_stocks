<?php
// VPS UTAMA /var/www/bot_stocks/scanner.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jakarta'); // Tambahkan baris ini

// --- SIMPLE LOCK ---
$lock_file = fopen(__FILE__, 'r');
if (!flock($lock_file, LOCK_EX | LOCK_NB)) {
    die("Akses Ditolak: Script masih berjalan dari jadwal sebelumnya.\n");
}

// ✅ TAMBAHAN: Safety-net — pastikan lock selalu dilepas saat script berakhir,
//    termasuk jika ada exit() yang terlupa di kode manapun.
register_shutdown_function(function () use ($lock_file) {
    if (is_resource($lock_file)) {
        flock($lock_file, LOCK_UN);
        fclose($lock_file);
    }
    echo "\n🔓 [LOCK] flock dilepas.\n";
});

set_time_limit(0);
include 'config.php';
include 'functions.php';
include 'bandar_logic.php';
include 'police.php';
include 'tracker.php';

require_once __DIR__ . '/inc/MacroElasticityEngine.php';

// --- 1. FILTER HARI & JAM (STANDAR) ---
$hari = date('N');
$jam = date('H:i');
$today = date('Y-m-d');

// Standby Weekend
if ($hari > 5) {
    die("Sistem Standby: Weekend.\n");
}

// --- 2. CEK LIBUR NASIONAL DARI DATABASE ---
$check_holiday = $conn->prepare("SELECT description FROM bursa_calendar WHERE holiday_date = ? AND is_active = 1 LIMIT 1");
$check_holiday->bind_param("s", $today);
$check_holiday->execute();
$result_holiday = $check_holiday->get_result();

if ($result_holiday->num_rows > 0) {
    $row_h = $result_holiday->fetch_assoc();
    die("Sistem Standby: Hari Libur Bursa (" . $row_h['description'] . ").\n");
}

// --- 3. FILTER JAM SESI (TERMASUK JUMAT) ---
if ($hari == 5) {
    // JUMAT
    $is_sesi_1 = ($jam >= "09:05" && $jam <= "11:30");
    $is_sesi_2 = ($jam >= "13:55" && $jam <= "16:05");
} else {
    // SENIN - KAMIS
    $is_sesi_1 = ($jam >= "09:05" && $jam <= "12:00");
    $is_sesi_2 = ($jam >= "13:25" && $jam <= "16:05");
}

if (!$is_sesi_1 && !$is_sesi_2) {
    die("Sistem Standby: Di luar jam operasional Bursa ($jam).\n");
}

$delay_micro = rand(1000000, 120000000);
$delay_detik = round($delay_micro / 1000000); // Definisikan ini agar echo tidak error
echo "[" . date('Y-m-d H:i:s') . "] Menunda eksekusi selama $delay_detik detik...\n";
usleep($delay_micro);

$limit_acak = rand(25, 30);
$sql = "SELECT ticker, sector, trade_role, commodity_ref FROM watchlist WHERE is_active = 1 
        ORDER BY last_sync ASC, RAND() 
        LIMIT $limit_acak";

$result = $conn->query($sql);
$total_saham = $result->num_rows;

echo "Memulai Scan Profesional pada $total_saham emiten...\n";

// --- 1. GLOBAL MARKET DATA & CACHING ---
echo "📡 Fetching Global Benchmarks & Commodities...\n";

// A. Ambil Kurs USD/IDR (Universal)
$usd_data = getHistory('IDR=X');
$prices_usdidr = (isset($usd_data['close'])) ? array_values(array_map('floatval', $usd_data['close'])) : null;
echo "💱 USD/IDR Sync Complete.\n";

// B. Ambil IHSG
$ihsg_data = getHistory('^JKSE');
$ihsg_pct = 0;
if (isset($ihsg_data['close']) && count($ihsg_data['close']) >= 2) {
    $ihsg_now  = end($ihsg_data['close']);
    $ihsg_prev = $ihsg_data['close'][count($ihsg_data['close']) - 2];
    $ihsg_pct  = ($ihsg_now - $ihsg_prev) / $ihsg_prev * 100;
}

// C. CACHING KOMODITAS UNIK (Anti-Bruteforce & Debug Mode)
$commodity_cache = [];
$sql_unique = "SELECT DISTINCT commodity_ref FROM watchlist 
               WHERE commodity_ref IS NOT NULL 
               AND commodity_ref != 'USDIDR=X' 
               AND is_active = 1";
$res_unique = $conn->query($sql_unique);

echo "🛠️ Starting Macro Debugger...\n";

if ($res_unique->num_rows === 0) {
    echo "⚠️ Info: Tidak ada referensi komoditas unik untuk di-cache.\n";
}

while ($u = $res_unique->fetch_assoc()) {
    $ref = $u['commodity_ref'];

    // Jeda acak antar komoditas (8-14 detik)
    $jeda_makro = rand(8, 14);
    echo "⏳ Antre Makro {$jeda_makro}s untuk [$ref]... ";
    sleep($jeda_makro);

    // Try-Catch Sederhana dengan pengecekan data
    try {
        $c_raw = getHistory($ref);

        // DEBUG: Cek apakah key 'close' ada dan merupakan array
        if (isset($c_raw['close']) && is_array($c_raw['close']) && count($c_raw['close']) >= 2) {
            $commodity_cache[$ref] = array_values(array_map('floatval', $c_raw['close']));
            echo "✅ SUCCESS: " . count($commodity_cache[$ref]) . " data points cached.\n";
        } else {
            // Log Error jika format data salah
            echo "❌ ERROR: Data format invalid for $ref. Raw Response: " . (isset($c_raw['error']) ? $c_raw['error'] : 'Unknown Error') . "\n";
            $commodity_cache[$ref] = null; // Tandai gagal
        }
    } catch (Exception $e) {
        echo "❌ CRITICAL: Exception caught for $ref: " . $e->getMessage() . "\n";
        $commodity_cache[$ref] = null;
    }
}
echo "🚀 Macro Sync Complete.\n\n";


// --- 2. SYNC STATUS PASAR ---
$sector_mood = []; // Inisialisasi agar loop di bawah tidak error
echo "✅ Market Mood Sync Complete.\n\n";

$threshold = rand(9, 14);
$counter = 0;
while ($row = $result->fetch_assoc()) {
    $counter++;
    $ticker = $row['ticker'];
    $sector = $row['sector'];
    $snr_text = "";

    if (!$conn->ping()) {
        echo "\n🔄 [RECONNECT] Koneksi database terputus, mencoba menghubungkan kembali...";

        // Tutup koneksi lama jika masih ada sisa resource
        @$conn->close();

        // Panggil ulang config
        include 'config.php';

        // Opsional: Cek apakah setelah include koneksi benar-benar pulih
        if ($conn->connect_error) {
            echo "❌ Reconnect Gagal: " . $conn->connect_error . "\n";
            continue; // Skip emiten ini dan coba lagi di emiten berikutnya
        }
    }

    // 1. JEDA WAJIB (ANTRE) - Selalu jalan agar tidak spam request
    if ($counter > 1) {
        $jeda_detik = rand(8, 14);
        echo "\n(Antre {$jeda_detik}s...) ";
        sleep($jeda_detik);
    }

    // 2. JEDA BESAR (REST AREA) - Untuk kamuflase bot yang lebih kuat
    if ($counter >= $threshold && $counter < $total_saham) {
        $istirahat = rand(15, 30);
        echo "\n☕ [REST AREA] Istirahat $istirahat detik agar aman...";
        sleep($istirahat);

        // Set target istirahat berikutnya (8-15 emiten lagi)
        $threshold = $counter + rand(8, 15);
    }

    // 3. CETAK STATUS (Cukup satu kali saja agar rapi)
    echo "\n[$counter/$total_saham] Memproses $ticker... ";

    // --- FORCING OUTPUT (Agar log muncul real-time di browser/terminal) ---
    if (php_sapi_name() !== 'cli') {
        echo str_pad('', 4096);
        @ob_flush();
        flush();
    } else {
        // Jika di CLI/Terminal
        @ob_flush();
        flush();
    }

    $data = getHistory($ticker);

    // ✅ TAMBAHAN: Cek flag rate limit — break bersih agar flock terlepas via shutdown
    if (isset($data['error'])) {
        if ($data['error'] === 'RATE_LIMIT_429') {
            echo "🛑 [SCANNER] Rate limit terdeteksi. Menghentikan loop secara bersih.\n";
            if (function_exists('sendTelegram')) {
                sendTelegram("🛑 <b>Scanner Berhenti</b>\nYahoo Finance rate limit (429).\nBot akan cooldown 1 jam.");
            }
            break; // ✅ Keluar dari while loop — shutdown_function akan melepas flock
        }
        if ($data['error'] === 'RATE_LIMIT_COOLDOWN') {
            // Masih dalam masa cooldown — skip ticker ini, lanjut ke berikutnya
            echo "⏳ [SCANNER] $ticker di-skip (cooldown aktif).\n";
            continue;
        }
    }

    if (!isset($data['close']) || count($data['close']) < 100) {
        $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
        echo "❌ Skip $ticker: Data tidak cukup.\n";
        continue;
    }

    // 2. PERBAIKAN DATA AWAL (Versi lebih kuat)
    // Kita bersihkan index dan paksa jadi angka (float) dalam satu baris
    $prices  = array_values(array_map('floatval', $data['close']));
    $volumes = array_values(array_map('floatval', $data['volume']));
    $highs   = array_values(array_map('floatval', $data['high']));
    $lows    = array_values(array_map('floatval', $data['low']));

    // --- INTEGRASI MACRO ELASTICITY ENGINE (HYBRID MODE) ---
    $comm_ref = $row['commodity_ref'];
    $trade_role = $row['trade_role'];

    // Inisialisasi default: Anggap aman jika data tidak ada
    $macro = ['decision' => 'PROCEED', 'desc' => 'Macro Data Missing/Skipped'];

    // Ambil data dari cache
    $prices_commodity = (isset($commodity_cache[$comm_ref])) ? $commodity_cache[$comm_ref] : null;

    // Eksekusi Engine HANYA JIKA data referensi tersedia
    if (!empty($comm_ref) && !empty($prices_commodity)) {
        $macro = MacroElasticityEngine::checkFeasibility(
            $ticker,
            $sector,
            $trade_role,
            $prices_commodity,
            $prices_usdidr
        );

        // Filter Veto tetap bekerja jika data ADA dan hasilnya memang bahaya
        if ($macro['decision'] === "VETO_HALT") {
            echo "LOG: $ticker VETO MACRO - {$macro['desc']}\n";
            $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
            continue; // Khusus Veto asli tetap skip demi keamanan
        }
    } else {
        // Jika data gagal ditarik, tidak perlu continue (skip). 
        // Script akan tetap meluncur ke bawah memproses indikator teknikal.
        if (!empty($comm_ref)) {
            echo "LOG: $ticker Macro Info: Data [$comm_ref] tidak tersedia, memproses teknikal saja...\n";
        }
    }

    // --- A. HITUNG INDIKATOR ---
    $macd  = calculateMACD($prices);
    $stoch = calculateStochastic([
        'close' => $prices,
        'high'  => $highs,
        'low'   => $lows
    ]);
    $adx_data = getADXData($highs, $lows, $prices);
    $rsi_history = calculateFullRSI($prices);
    $rsi = end($rsi_history);
    $struct = getMarketStructure($prices, $volumes, $highs, $lows);

    // --- NEW: AUDIT CANDLESTICK (MENGGUNAKAN PHP TRADER) ---
    $opens = array_values(array_map('floatval', $data['open']));

    // 1. FILTER VETO (Tetap ada untuk membuang Marubozu Merah)
    if (CandlePolice::hasHighSellingPressure($opens, $highs, $lows, $prices)) {
        echo "LOG: $ticker REJECTED - Tekanan Jual Masif (Marubozu Merah).\n";
        $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
        continue;
    }


    // --- TAMBAHKAN INI: AMBIL KONFIRMASI POLA ---
    $bullish_pattern = CandlePolice::isBullishReversal($opens, $highs, $lows, $prices);
    $is_indecision   = CandlePolice::isIndecision($opens, $highs, $lows, $prices);

    if ($macd === null || $stoch === null || $struct === null) {
        $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
        continue;
    }

    // --- B. DEFINISIKAN NILAI ---
    $k_val = round($stoch['k'], 2);
    $current_price = end($prices);

    // --- E. LOGIKA VOLUME DINAMIS (CURI START & APPLE-TO-APPLE) ---
    $avg_vol_20 = calculateAvgVolume($volumes, 20);
    $vol_ratio_20 = ($avg_vol_20 > 0) ? round(end($volumes) / $avg_vol_20, 2) : 0;
    $vol_yesterday = (count($volumes) >= 2) ? $volumes[count($volumes) - 2] : 0;
    $vol_vs_yesterday = ($vol_yesterday > 0) ? round(end($volumes) / $vol_yesterday, 2) : 0;

    // --- INTEGRASI MOMENTUM HISTOGRAM MACD ---
    $is_macd_gc = isset($macd['is_gc']) ? $macd['is_gc'] : false;
    $hist_current = isset($macd['histogram'][0]) ? $macd['histogram'][0] : 0;
    $hist_prev = isset($macd['histogram'][1]) ? $macd['histogram'][1] : 0;

    // Logika perbaikan momentum
    $is_momentum_improving = ($hist_current > $hist_prev);

    // 2. Siapkan teks singkat untuk pesan reguler
    $snr_text = "";
    if (function_exists('getDynamicZones')) {
        $swings_raw = getSwings($prices);
        $snr = getDynamicZones($prices, $swings_raw['highs'], $swings_raw['lows'], $highs, $lows);

        if ($snr['support_1']) {
            $snr_text = "📐 <b>S1:</b> <code>" . $snr['support_1']['base'] . "</code> | ";
            $snr_text .= "<b>R1:</b> <code>" . ($snr['resistance_1'] ?? 'N/A') . "</code>\n";
            $snr_text .= "📐 <b>S2:</b> <code>" . ($snr['support_2']['base'] ?? 'N/A') . "</code> | ";
            $snr_text .= "<b>R2:</b> <code>" . ($snr['resistance_2'] ?? 'N/A') . "</code>\n";
        }
    }
    // --- HITUNG FASE PASAR (HILBERT) ---
    $market_phase = getMarketPhase($prices);
    $is_trending_up = ($market_phase['mode'] === "TRENDING" && $market_phase['label'] === "UPTREND");

    // --- C. FILTER HARGA PUCUK ---
    $is_super_bullish = (
        $rsi > 70 &&
        isMomentumValid($rsi_history) &&
        $is_momentum_improving &&
        $is_trending_up
    );

    if ($k_val > 90 && $is_super_bullish) {
        echo "LOG: $ticker Momentum dibatalkan karena Stoch K ($k_val) > 90 (Ekstrem).\n";
        $is_super_bullish = false;
    }

    if (($k_val > 80 || $rsi > 75) && !$is_super_bullish && $vol_ratio_20 < 1.2) {
        $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
        echo "LOG: $ticker Dibuang (Pucuk dan Volume Sepi).\n";
        continue;
    }

    $is_div = isset($struct['is_divergence']) ? $struct['is_divergence'] : false;

    $is_adx_bullish = true;
    if ($adx_data) {
        // Kondisi Awal: Tren Turun Kuat
        if ($adx_data['adx'] > 25 && $adx_data['minus_di'] > $adx_data['plus_di']) {

            $is_adx_bullish = false;

            // PENGECUALIAN 1: Jika ADX mulai menukik (Tren turun melemah)
            // Membandingkan ADX hari ini dengan kemarin
            $adx_slope = $adx_data['adx'] - $adx_data['prev_adx'];
            if ($adx_slope < -0.5) {
                $is_adx_bullish = true; // Tenaga turun mulai habis, boleh lirik GC
            }

            // PENGECUALIAN 2: Bullish Divergence (Hukumnya lebih tinggi dari ADX)
            if ($is_div) {
                $is_adx_bullish = true;
            }
        }
    }

    // --- LOGIKA PARTIAL HARD FILTER STOCHASTIC (DENGAN FILTER POLA) ---
    $is_stoch_gc = false;
    $stoch_raw_gc = (isset($stoch['is_gc']) && $stoch['is_gc'] === true);

    if ($stoch_raw_gc) {
        // SYARAT MUTLAK: Harus ada pola Bullish (Hammer, Engulfing, dll) ATAU minimal Doji/Spinning Top
        $has_visual = ($bullish_pattern !== false || $is_indecision === true);

        if ($has_visual) {
            /**
             * AREA 1: BOTTOM REBOUND (K <= 35)
             */
            if ($k_val <= 35) {
                $adx_slope = $adx_data['adx'] - $adx_data['prev_adx'];
                if ($adx_slope < -0.2 || $adx_data['adx'] < 45 || $is_div) {
                    $is_stoch_gc = true;
                }
            }
            /**
             * AREA 2: NEUTRAL ZONE (35 < K <= 70)
             */
            elseif ($k_val > 35 && $k_val <= 70) {
                if ($is_adx_bullish) {
                    $is_stoch_gc = true;
                }
            }
            /**
             * AREA 3: MOMENTUM ZONE (K > 70)
             */
            elseif ($k_val > 70 && $k_val <= 85) {
                if ($is_trending_up && $is_adx_bullish) {
                    $is_stoch_gc = true;
                }
            }
        } else {
            // Jika GC tapi candle letoy/biasa saja, kita biarkan $is_stoch_gc tetap FALSE
            echo "LOG: $ticker Stoch GC di-skip karena tidak ada konfirmasi pola visual.\n";
        }
    }

    // Tambahkan pengaman Momentum Improving agar sinyal bertenaga
    if ($is_stoch_gc && !$is_momentum_improving && !$is_div) {
        $is_stoch_gc = false; // Batalkan jika histogram MACD tidak mendukung (kecuali Div)
    }

    $is_special_pattern = (strpos($struct['label'], 'Wave') !== false);

    if ($jam <= "10:30") {
        /**
         * FASE 1: EARLY MORNING (VS KEMARIN)
         * Jam 09:00 - 10:30
         * Target normal 0.4 (40%), kita diskon ke 0.38 untuk curi start.
         */
        $adj_vol_power = round($vol_vs_yesterday / 0.38, 2);
        $pass_vol = ($vol_vs_yesterday >= 0.38);
    } else {
        /**
         * FASE 2: NORMALIZATION (VS AVG 20D)
         * Target bertahap agar adil (Apple-to-Apple)
         */
        $target_vol = 0.5; // Default jika jam tidak terdefinisi

        if ($jam > "10:30" && $jam <= "12:00") {
            // Sesi 1 Akhir: Target 28% (Curi start dari normalnya 30%)
            $target_vol = 0.28;
        } elseif ($jam >= "13:30" && $jam <= "15:00") {
            // Sesi 2 Awal/Tengah: Target 55% (Curi start dari normalnya 60%)
            $target_vol = 0.55;
        } elseif ($jam > "15:00") {
            // Menjelang Closing: Target 75% (Curi start dari normalnya 80%)
            $target_vol = 0.75;
        }

        $adj_vol_power = $vol_ratio_20;
        $pass_vol = ($vol_ratio_20 >= $target_vol);
    }

    // SINKRONISASI: Menentukan angka volume yang akan dilaporkan ke Telegram & Tracker
    $vol_for_scoring = ($jam <= "10:30") ? $adj_vol_power : $vol_ratio_20;

    // 1. Hitung ATR 20D menggunakan PHP Trader
    $atr_array = trader_atr($highs, $lows, $prices, 20);
    $current_atr_20d = end($atr_array);

    // 2. Siapkan data harga untuk Polisi
    $price_audit = [
        'today'     => end($prices),
        'yesterday' => $prices[count($prices) - 2],
        'high'      => end($highs),
        'low'       => end($lows),
        'all_close' => $prices,
        'all_high'  => $highs,
        'all_low'   => $lows
    ];

    // =========================================================================
    // PATCH #3: INISIALISASI $score = 0 HARUS DI ATAS SEMUA LOGIKA PENALTI
    // =========================================================================
    // Bug sebelumnya: $score -= 4 (penalti sektor) dieksekusi SEBELUM $score = 0,
    // sehingga pada iterasi pertama $score dimulai dari -4, bukan 0.
    // Pada iterasi berikutnya, nilai $score dari ticker sebelumnya masih tersisa
    // di memori (karena include tidak membuat scope baru) sebelum di-nol-kan.
    $score = 0;

    // --- OPTIMASI PROBABILITAS: PENALTI SEKTOR BERAT (HEAVY SECTORS) ---
    $heavy_sectors = ['Financial Services', 'Consumer Defensive', 'Industrials'];

    if (in_array($row['sector'], $heavy_sectors) && $vol_for_scoring < 0.8) {
        echo "LOG: $ticker Penalti Skor - Sektor Berat dengan Volume Rendah.\n";
        $score -= 4;
    }

    $mfi_results = calculateMFI($highs, $lows, $prices, $volumes, 14);

    // =========================================================================
    // --- [SUNTIKAN UTAMA: GERBANG ANTI-NOISE SIKLUS KUANTITATIF FOR GC] ---
    // =========================================================================

    // 1. Ambil Jam Arloji Siklus Pasar Real-Time via Hilbert Transform
    $dcphase_series_global = trader_ht_dcphase($prices);
    $current_phase_global  = ($dcphase_series_global !== false) ? end($dcphase_series_global) : null;

    // 2. Evaluasi Ulang Status Stochastic GC Jangka Pendek
    // 2. Evaluasi Ulang Status Stochastic GC Jangka Pendek
    if ($is_stoch_gc) {
        /**
         * AKSES FILTER AREA 1: BOTTOM REBOUND (K <= 35)
         * Stochastic GC di bawah hanya boleh lolos jika Jam Siklus Kuantitatif sudah masuk 
         * area akumulasi akhir (250° - 360°).
         * Hanya jalankan pemblokiran JIKA kalkulasi Hilbert berhasil (!== null).
         */
        if ($k_val <= 35) {
            if ($current_phase_global !== null) {
                $is_cycle_mature = ($current_phase_global >= 250.0 || $current_phase_global <= 10.0);

                if (!$is_cycle_mature && !$is_div) {
                    $is_stoch_gc = false; // LOCK REM: Matikan sinyal karena noise longsor belum usai
                    echo "LOG: $ticker Stoch GC Blocked (Noise: Harga bawah tapi siklus jatuh belum selesai: " . round($current_phase_global, 1) . "°).\n";
                }
            } else {
                // Fallback: Jika data HT gagal memproses, loloskan sinyal berbasis technical indicators murni
                echo "LOG: $ticker Info - Hilbert Transform tidak stabil, bypass filter siklus global harian.\n";
            }
        }
        /**
         * AKSES FILTER AREA 2: NEUTRAL ZONE (35 < K <= 70)
         * Di area transisi tengah, Stochastic GC hanya valid jika pasar global dikonfirmasi TRENDING oleh Hilbert.
         */
        elseif ($k_val > 35 && $k_val <= 70) {
            if (!$is_market_trending || !$is_adx_bullish) {
                $is_stoch_gc = false; // LOCK REM: Matikan sinyal karena market sideways cincang
                echo "LOG: $ticker Stoch GC Blocked (Noise: Area tengah wajib Trending State).\n";
            }
        }
    }

    // 3. Evaluasi Ulang Status MACD GC Jangka Menengah
    if ($is_macd_gc) {
        /**
         * ANTI-NOISE MACD SIDEWAYS VS TRENDING
         */
        if (!$is_market_trending) {
            // Jika market sedang SIDEWAYS/CYCLING, MACD GC yang menumpuk kusut wajib dibatalkan, 
            // KECUALI jika emiten divalidasi memiliki kompresi ledakan energi sepi (Dry-out/VCP)
            $explosion_check = checkExplosionPotential($highs, $lows, $volumes, $prices);
            $is_accumulation_valid = ($explosion_check['is_dry_out'] || $explosion_check['is_vcp']);

            if (!$is_accumulation_valid && !$is_div) {
                $is_macd_gc = false; // LOCK REM: Matikan sinyal jaring kusut
                echo "LOG: $ticker FRESH MACD GC Bloked (Noise: Sideways letoy tanpa kompresi bandar).\n";
            }
        } else {
            // Jika market TRENDING, MACD GC wajib didukung perluasan bar histogram harian (akselerasi)
            if (!$is_momentum_improving) {
                $is_macd_gc = false; // LOCK REM: Matikan sinyal daya kempis
                echo "LOG: $ticker FRESH MACD GC Bloked (Noise: Histogram MACD kehilangan daya dorong).\n";
            }
        }
    }
    // =========================================================================
    // --- [END SUNTIKAN ANTI-NOISE] ---
    // =========================================================================

    // =========================================================================
    // PATCH #2: RESET VARIABEL ANTAR-TICKER — WAJIB SEBELUM include
    // =========================================================================
    // Masalah: include 'logic_premium.php' TIDAK membuat scope baru di PHP.
    // Semua variabel dari iterasi ticker sebelumnya masih hidup di memori.
    // Tanpa reset ini, logika Spring/Fibo/VDO bisa memakai $last_h, $last_l,
    // $snr, $explosion dari ticker sebelumnya jika kondisi tidak terpenuhi
    // di ticker ini — keputusan beli/sinyal berdasarkan data ticker lain.
    $last_h          = null;
    $last_l          = null;
    $last_h_idx      = null;
    $last_l_idx      = null;
    $snr             = null;
    $explosion       = null;
    $rubber          = null;
    $spring          = null;
    $sky             = null;
    $resA1           = null;
    $resVDO          = null;
    $is_infoA1       = "";
    $bullish_pattern = false;
    $is_indecision   = false;
    $current_phase   = 0;
    // =========================================================================

    include 'logic_premium.php';

    // --- 5. TRIGGER NOTIFIKASI (Satu Gerbang Final) ---
    $is_price_up = (end($prices) >= $prices[count($prices) - 2]);
    if ($pass_vol && $is_price_up && ($is_macd_gc || $is_stoch_gc || $is_div || $is_super_bullish)) {
        // --- OPTIMASI PROBABILITAS: ANTI VOLUME TRAP ---
        $vol_prev = (count($volumes) >= 2) ? $volumes[count($volumes) - 2] : 0;
        $vol_now = end($volumes);

        // Jika kenaikan harga tidak didukung volume (Volume drop > 30% dari kemarin)
        if ($vol_now < ($vol_prev * 0.7) && !$is_div) {
            echo "LOG: $ticker REJECTED - Volume Trap (Harga naik tapi tenaga turun).\n";
            $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
            continue; // Jangan kirim ke Telegram
        }

        // GERBANG VETO MUTLAK: Tanya Polisi cukup sekali di sini
        // Di dalam scanner.php, saat pengecekan Veto
        $is_valid_momentum = isMomentumValid(
            $rsi_history,
            $vol_for_scoring,
            $stoch,
            $current_atr_20d,
            $price_audit,
            $mfi_results
        );

        // LOGIKA PASPOR DIPLOMATIK
        if ($is_div) {
            // Jika Divergence, Polisi hanya cek apakah Slope POSITIF (tidak perlu tinggi)
            $slope_sekarang = end($rsi_history) - $rsi_history[count($rsi_history) - 2];
            if ($slope_sekarang <= 0.5) { // Toleransi sangat rendah untuk Divergence
                echo "LOG: $ticker Divergence Ditolak (Slope terlalu letoy/negatif).\n";
                $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
                continue;
            }
        } else {
            // Jika BUKAN Divergence, baru terapkan aturan galak Police.php
            if (!$is_valid_momentum) {
                echo "LOG: $ticker Ditolak Police (Momentum Standar Lemah).\n";
                $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
                continue;
            }
        }

        // --- LOGIKA ANTI-SPAM 24 JAM ---
        // Mencari apakah ticker yang sama sudah dikirim dalam 18 jam terakhir dari detik ini
        $check_s = $conn->prepare("
          SELECT id FROM signal_logs 
           WHERE ticker = ? 
           AND created_at > NOW() - INTERVAL 18 HOUR 
           AND rsi_value != 999
      ");
        $check_s->bind_param("s", $ticker);
        $check_s->execute();

        if ($check_s->get_result()->num_rows == 0) {
            $vol_status = ($vol_ratio_20 >= 1.5) ? "🔥 BOOM VOLUME" : ($vol_ratio_20 >= 1.0 ? "✅ NORMAL" : "💤 ACCUM");

            // ==========================================================
            // --- 1. PENENTUAN SKOR DASAR STRUKTUR (BASE SCORE) ---
            // ==========================================================
            $label = $struct['label'];
            $base_score = 0;

            if (strpos($label, 'Wave 3') !== false) {
                $base_score = 4;
            } elseif (strpos($label, 'Wave 5') !== false || strpos($label, 'Wave 1') !== false) {
                $base_score = 2;
            } elseif (strpos($label, 'WXYXZ') !== false) {
                $base_score = 2;
            } elseif (strpos($label, 'ABCDE') !== false) {
                $base_score = 2;
            } elseif (strpos($label, 'ABC') !== false || strpos($label, 'XYZ') !== false) {
                $base_score = 2;
            }

            $score += $base_score;

            // ==========================================================
            // --- 2. BONUS MOMENTUM ---
            // ==========================================================
            $is_double_gc = ($is_macd_gc && $is_stoch_gc);
            if ($is_double_gc) {
                $score += 16;
            } else {
                if ($is_macd_gc)  $score += 10; // Langsung ambang batas minimal
                if ($is_stoch_gc) $score += 6;  // Naikkan sedikit dari 5
            }

            // ==========================================================
            // --- 3. BONUS DIVERGENCE ---
            // ==========================================================
            if ($is_div) {
                $score += 5;
                if ($is_double_gc) {
                    $score += 2;
                }
            }

            // ==========================================================
            // --- 3b. BONUS INDIKATOR (RSI & STOCHASTIC) ---
            // ==========================================================

            // --- Skor RSI (Makin rendah RSI, makin tinggi skor pantul) ---
            if ($rsi <= 30) {
                $score += 7; // Super Oversold
            } elseif ($rsi <= 40) {
                $score += 5; // Accumulation Area
            } elseif ($rsi <= 50) {
                $score += 3; // Neutral to Bullish
            }

            // --- Skor Stochastic (Mencari momentum GC di area bawah) ---
            if ($k_val <= 20) {
                $score += 5; // Dasar banget
            } elseif ($k_val <= 40) {
                $score += 3; // Area transisi
            }

            // --- Bonus Ekstra: Sinergi RSI & Stoch ---
            // Jika keduanya sama-sama di bawah 35, kasih bonus "Perfect Bottom"
            if ($rsi < 35 && $k_val < 30) {
                $score += 5;
            }

            // ==========================================================
            // --- 4. BONUS VOLUME (DINAMIS SESUAI JAM) ---
            // ==========================================================

            $vol_for_scoring = ($jam <= "10:30") ? $adj_vol_power : $vol_ratio_20;

            if ($vol_for_scoring >= 2.0) {
                $score += 7;
            } elseif ($vol_for_scoring >= 1.5) {
                $score += 5;
            } elseif ($vol_for_scoring >= 1.0) {
                $score += 3;
            }

            // ==========================================================
            // --- 5. SYSTEM PENALTI (FILTER DISIPLIN) ---
            // ==========================================================

            // --- PERBAIKAN: Ambil nilai dari array $macd hasil calculateMACD ---
            $macd_val    = $macd['macd'] ?? 0;
            $signal_val  = $macd['signal'] ?? 0;

            if ($k_val > 69 && !$is_super_bullish) {
                $score -= 8; // Penalti Pucuk untuk saham biasa
            }

            // --- Tentukan Deskripsi MACD ---
            if ($macd_val < 0 && $macd_val < $signal_val) {
                $macd_desc = "Bearish Trend 📉";
            } else {
                $macd_desc = "Bullish Momentum 📈";
            }

            // CEK PENALTI BERDASARKAN DESKRIPSI
            if (strpos($macd_desc, 'Bearish Trend') !== false) {
                // Jika sedang Divergence, jangan dihukum berat
                if ($is_div) {
                    $score -= 0; // Paspor diplomatik untuk Divergence
                } else {
                    $score -= 2; // Dikurangi sedikit saja agar tidak terlalu pelit
                }
            }
            // Penalti jika tidak ada struktur DAN tidak ada divergence
            if ($base_score == 0 && !$is_div) {
                $score -= 3;
            }

            $score = max(0, $score);

            // Tambahan icon untuk notif
            $is_wave_3 = (strpos($label, 'Wave 3') !== false);
            $premium_icon = $is_double_gc ? "⚡ " : "";
            $pattern_icon = $is_wave_3 ? "🚀 " : "";
            $div_icon = $is_div ? "💎 " : "";

            // Header menggunakan tag HTML <b>
            $slope_icon = (isset($macd['slope']) && $macd['slope'] == "UP") ? "⤴️" : "⤵️";

            $macd_desc = $is_macd_gc ? "FRESH GC! ✅" : (($macd['macd'] > $macd['signal']) ? "Bullish Trend 📈" : "Bearish Trend 📉") . " ($slope_icon)";
            $stoch_desc = $is_stoch_gc ? "$k_val (FRESH GC! ✅)" : "$k_val";
            $div_label = $is_div ? "\n🔔 <b>DIVERGENCE ALERT!</b> 💎" : "";

            // Membungkus label agar anti-error (Anti-crash HTML)
            $safe_label = htmlspecialchars($struct['label']);
            $safe_trigger = htmlspecialchars($struct['trigger']); // Jika ingin menampilkan trigger juga

            // --- 6. KONFIRMASI BANDARMOLOGI (GOAPI) ---
            $clean_ticker = str_replace('.JK', '', $ticker);
            $analisis_bdm = getBandarAnalysis($clean_ticker, $conn);
            $bdm_text = "";

            if (is_array($analisis_bdm) && !in_array($analisis_bdm['kesimpulan'], ["💤 Data Belum Tersedia", "💤 Mode Teknikal", "⚠️ Limit API", "⚠️ API Expired"])) {
                $bdm_text = "👥 <b>Bandarmologi :</b> " . $analisis_bdm['kesimpulan'] . "\n\n";
                $bdm_text .= "📥 <b>NET BUY  :</b>\n" . $analisis_bdm['top_buy'] . "\n\n";
                $bdm_text .= "📤 <b>NET SELL :</b>\n" . $analisis_bdm['top_sell'] . "\n\n";
                $bdm_text .= $analisis_bdm['footer_ritel'] . "\n\n";

                // Update Score berdasarkan Bandar
                if (strpos($analisis_bdm['kesimpulan'], '💎') !== false) $score += 10;
                if (strpos($analisis_bdm['footer_ritel'], '🔥') !== false) $score += 5;

                // Tambahkan ini di scanner.php setelah logika bonus bandar

                if (strpos($analisis_bdm['kesimpulan'], 'DISTRIBUTION') !== false) {
                    $score -= 10; // Potong 10 poin jika bandar jualan masif
                }
                if (strpos($analisis_bdm['footer_ritel'], 'Retail Driven') !== false) {
                    $score -= 5; // Potong 5 poin jika hanya ritel yang beli
                }
            }

            // --- 7. PENENTUAN TIER & BINTANG (FINAL) ---
            if ($score >= 25) {
                $header = "<b>🟢 TIER 1 - HIGH MOMENTUM</b>";
            } elseif ($score >= 15) {
                $header = "<b>🔵 TIER 2 - TREND SETUP</b>";
            } else {
                $header = "<b>🟡 TIER 3 - WATCHLIST</b>";
            }

            $num_stars = min(floor($score / 5), 5);
            if ($score > 0 && $num_stars == 0) $num_stars = 1;
            $stars = ($score > 0) ? str_repeat("⭐", $num_stars) : "❌ (No Rating)";

            // ==========================================================
            // --- FINAL VETO: ADAPTIVE SCORING (BERTINGKAT) ---
            // ==========================================================

            if ($ihsg_pct <= -1.5) {
                $final_min_score = 25; // Mode Darurat
                $mode_log = "EMERGENCY";
            } elseif ($ihsg_pct <= -1.0) {
                $final_min_score = 22; // Mode Waspada Tinggi
                $mode_log = "HIGH ALERT";
            } elseif ($ihsg_pct <= -0.5) {
                $final_min_score = 18; // Mode Caution (Batas Awal)
                $mode_log = "CAUTION";
            } else {
                $final_min_score = 13; // Mode Normal
                $mode_log = "NORMAL";
            }

            // Cek apakah skor emiten memenuhi ambang batas mode saat ini
            if ($score < $final_min_score) {
                echo "LOG: $ticker SKIP (Mode $mode_log: Butuh $final_min_score. Skor: $score).\n";
                $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
                continue;
            }

            // --- Header & Tier ---
            $msg = "<b>$header</b>\n";
            $msg .= "$stars\n";
            $msg .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

            // --- Info Saham (Simetris) ---
            $msg .= "💎 <b>STOCK : $premium_icon$ticker</b> $div_label\n";
            $msg .= "📂 <b>Sector :</b> <code>" . ($row['sector'] ?? 'N/A') . "</code>\n\n";
            $msg .= "📊 Pattern : <code>$safe_label</code>\n\n";
            $msg .= "💰 Price   : <b>Rp " . number_format($current_price, 0, ',', '.') . "</b>\n\n";
            $msg .= $snr_text . "\n\n";
            $msg .= "📈 Vol/Yesterday : <code>{$vol_vs_yesterday}x</code>\n";
            $msg .= "📈 Vol Ratio 20D  : <code>{$vol_for_scoring}x</code> ($vol_status)\n\n";

            // INI KUNCINYA: Jika bdm_text kosong (karena error API), bagian ini tidak akan tertulis sama sekali
            if (!empty($bdm_text)) {
                $msg .= $bdm_text;
            } else {
                // Opsional: Beri garis tipis saja jika ingin pemisah, atau kosongkan sama sekali
                $msg .= "<code>" . str_repeat("—", 25) . "</code>\n\n";
            }

            // --- Indikator (Simetris) ---
            $msg .= "⚡ <b>Indicator Status:</b>\n";
            $msg .= "• MACD : <code>$macd_desc</code>\n";
            $msg .= "• Stoch  : <code>$stoch_desc</code>\n";
            $msg .= "• RSI       : <code>" . round($rsi, 2) . "</code>\n\n";

            $msg .= "🕒 <code>" . date("d M Y H:i") . " WIB</code>\n\n";
            $msg .= "📡 Source: " . VPS_ID . "\n";
            $msg .= "<code>" . str_repeat("—", 25) . "</code>\n\n";

            // --- 8. KIRIM NOTIFIKASI & CATAT DATA (VERSI BERSIH) ---
            if (sendTelegram($msg)) {
                // A. Catat ke Signal Logs (Anti-Spam)
                $m_val = $is_macd_gc ? 1 : 0;
                $stmt = $conn->prepare("INSERT INTO signal_logs (ticker, rsi_value, is_macd_golden_cross) VALUES (?, ?, ?)");
                $stmt->bind_param("sdi", $ticker, $rsi, $m_val);
                $stmt->execute();
                $stmt->close();

                // B. Kumpulkan Alasan Sinyal
                $reasons = [];
                if ($is_macd_gc) $reasons[] = "MACD_GC";
                if ($is_stoch_gc) $reasons[] = "STOCH_GC";
                if ($is_div) $reasons[] = "DIVERGENCE";
                if ($is_super_bullish) $reasons[] = "MOMENTUM";
                $reason_txt = implode(", ", $reasons);

                // C. Simpan ke Database Tracker dengan Parameter Lengkap
                // Pastikan urutan parameter: $conn, $ticker, $price, $reason, $rsi, $k_val, $vol_ratio
                recordNewSignal(
                    $conn,
                    $ticker,
                    $current_price,
                    $reason_txt,
                    round($rsi, 2),
                    $k_val,
                    $vol_for_scoring
                );

                echo "LOG: Sinyal $ticker sukses dikirim dan dicatat.\n";
            }
            $check_s->close();
        }
    }

    $conn->query("UPDATE watchlist SET last_sync = NOW() WHERE ticker = '$ticker'");
}

echo "Scan Selesai.\n";
