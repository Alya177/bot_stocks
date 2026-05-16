<?php
// bandar_logic.php

function getBandarAnalysis($ticker, $db)
{
    static $api_failed_permanently = false;

    if ($api_failed_permanently) {
        return ["kesimpulan" => "💤 Mode Teknikal", "top_buy" => "-", "top_sell" => "-", "footer_ritel" => ""];
    }

    // --- DAFTAR API KEYS ---
    $apiKeys = [
        1 => "40f57f1d-4cb6-504e-7657-5646f0e8", // API Utama
        2 => "00557127-7f85-523b-7e5d-3b24cb03"  // API Kedua
    ];

    // --- 1. LOGIKA MENCARI HARI BURSA AKTIF ---
    // PATCH #1: Tambah batas iterasi (maks 60 hari) agar loop tidak hang permanen
    // jika tabel bursa_calendar kosong atau koneksi DB bermasalah.
    // Query menggunakan prepared statement (OOP) untuk konsistensi dan keamanan.
    $found     = false;
    $checkDate = date('Y-m-d', strtotime('-1 day'));
    $attempt   = 0;
    $maxAttempt = 60;

    while (!$found && $attempt < $maxAttempt) {
        $attempt++;
        $dayNum = date('N', strtotime($checkDate));
        if ($dayNum > 5) {
            $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
            continue;
        }
        // Ganti mysqli_query prosedural → prepared statement OOP (sinkron dengan codebase lain)
        $stmt_cal = $db->prepare("SELECT id FROM bursa_calendar WHERE holiday_date = ? AND is_active = 1 LIMIT 1");
        $stmt_cal->bind_param("s", $checkDate);
        $stmt_cal->execute();
        $isHoliday = ($stmt_cal->get_result()->num_rows > 0);
        $stmt_cal->close();

        if ($isHoliday) {
            $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
            continue;
        }
        $found = true;
    }

    // Jika melebihi batas, kembalikan fallback aman — jangan hang
    if (!$found) {
        echo "LOG: [getBandarAnalysis] ERROR - Tidak bisa menemukan hari bursa dalam {$maxAttempt} hari. Periksa tabel bursa_calendar.\n";
        return ["kesimpulan" => "⚠️ Error Kalender", "top_buy" => "-", "top_sell" => "-", "footer_ritel" => ""];
    }

    // --- 2. LOGIKA PEMILIHAN API DENGAN SISTEM GIGIH (RETRY ON THE FLY) ---
    $today = date('Y-m-d');

    // PATCH #1b: Seragamkan semua mysqli ke OOP dengan Prepared Statement penuh
    // agar reconnect via include 'config.php' di scanner tidak menyebabkan ghost resource.
    foreach ($apiKeys as $id => $key) {
        $stmt_api = $db->prepare("SELECT * FROM api_status WHERE id = ? LIMIT 1");
        $stmt_api->bind_param("i", $id);
        $stmt_api->execute();
        $status = $stmt_api->get_result()->fetch_assoc();
        $stmt_api->close();

        // Auto Reset Kuota jika ganti hari
        if ($status && $status['last_reset'] != $today) {
            $stmt_reset = $db->prepare("UPDATE api_status SET quota_used = 0, last_reset = ? WHERE id = ?");
            $stmt_reset->bind_param("si", $today, $id);
            $stmt_reset->execute();
            $stmt_reset->close();
            $status['quota_used'] = 0;
        }

        // Cek apakah API ini layak pakai (Kuota < 30 dan tidak ditandai mati/99)
        if (!$status || $status['quota_used'] >= 30) {
            continue; // Coba API berikutnya dalam list $apiKeys
        }

        // Jalankan Request untuk API yang terpilih
        $url = "https://api.goapi.io/stock/idx/{$ticker}/broker_summary?date={$checkDate}&investor=ALL";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-API-KEY: ' . $key
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // A. JIKA BERHASIL (HTTP 200)
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data['data']['results'])) {
                $stmt_inc = $db->prepare("UPDATE api_status SET quota_used = quota_used + 1 WHERE id = ?");
                $stmt_inc->bind_param("i", $id);
                $stmt_inc->execute();
                $stmt_inc->close();

                $result = processGoApiBrokerData($data['data']['results']);
                $result['footer_ritel'] .= "\n🔌 <i>via API-Slot: $id</i>";
                return $result;
            }
        }

        // B. JIKA MATI/EXPIRED (HTTP 401 atau 403)
        elseif ($httpCode === 401 || $httpCode === 403) {
            $stmt_kill = $db->prepare("UPDATE api_status SET quota_used = 99 WHERE id = ?");
            $stmt_kill->bind_param("i", $id);
            $stmt_kill->execute();
            $stmt_kill->close();
            echo "LOG: API Slot $id EXPIRED/MATI. Mencoba slot berikutnya...\n";
            continue;
        }
    }

    // Jika semua API dalam loop sudah dicoba dan gagal
    return ["kesimpulan" => "⚠️ All API Limits", "top_buy" => "-", "top_sell" => "-", "footer_ritel" => ""];
}

function processGoApiBrokerData($results)
{
    $net_data = [];

    // --- DAFTAR LENGKAP BROKER RITEL (Untuk Akurasi Power) ---
    $ritel_codes = [
        'XL',
        'YP',
        'XC',
        'PD',
        'KK',
        'CC',
        'OD',
        'AZ',
        'DR',
        'ID',
        'GR',
        'DH',
        'MG',
        'YJ',
        'AO',
        'EP',
        'NI',
        'IF',
        'CP',
        'IN',
        'XQ',
        'YI'
    ];

    // 1. Hitung Net Value SEMUA Broker yang ada di JSON
    foreach ($results as $item) {
        $broker = strtoupper($item['code']);
        $side = strtoupper($item['side']);
        $value = (float)$item['value'];

        if (!isset($net_data[$broker])) $net_data[$broker] = 0;
        $net_data[$broker] += ($side == 'BUY') ? $value : -$value;
    }

    // 2. Pisahkan Kelompok & Hitung Power Murni (Full Data)
    $bandar_buy_total = 0;
    $bandar_sell_total = 0;
    $ritel_buy_total = 0;
    $ritel_sell_total = 0;

    $net_buy_list = [];
    $net_sell_list = [];

    foreach ($net_data as $code => $val) {
        $is_ritel = in_array($code, $ritel_codes);

        if ($val > 0) {
            $net_buy_list[$code] = $val;
            if ($is_ritel) $ritel_buy_total += $val;
            else $bandar_buy_total += $val;
        } elseif ($val < 0) {
            $abs_val = abs($val);
            $net_sell_list[$code] = $abs_val;
            if ($is_ritel) $ritel_sell_total += $abs_val;
            else $bandar_sell_total += $abs_val;
        }
    }

    // 3. LOGIKA POWER (BANDAR vs RITEL) - Menggunakan SEMUA data yang ada
    $bandar_power = $bandar_buy_total + $ritel_sell_total;
    $ritel_power  = $ritel_buy_total + $bandar_sell_total;

    $total_market_val = $bandar_power + $ritel_power;
    $gap_power = ($total_market_val > 0) ? (($bandar_power - $ritel_power) / $total_market_val) * 100 : 0;

    // 4. Tentukan Pengendali
    if ($gap_power > 15) {
        $pengendali = "🔥 <b>Strong Bandar Control</b>";
    } elseif ($gap_power < -15) {
        $pengendali = "⚠️ <b>Retail Driven (High Risk)</b>";
    } else {
        $pengendali = "⚖️ <b>Balanced / Neutral</b>";
    }

    // 5. Kesimpulan Visual Berdasarkan Rasio Top 4
    arsort($net_buy_list);
    arsort($net_sell_list);

    // Jika data < 4, array_slice akan mengambil semua yang tersedia tanpa error
    $top4_buy_val = array_sum(array_slice($net_buy_list, 0, 4));
    $top4_sell_val = array_sum(array_slice($net_sell_list, 0, 4));
    $ratio = ($top4_sell_val > 0) ? $top4_buy_val / $top4_sell_val : 0;

    if ($ratio >= 1.5)      $kesimpulan = "💎 <b>BIG ACCUM</b>";
    elseif ($ratio >= 1.2)  $kesimpulan = "✅ <b>ACCUM</b>";
    elseif ($ratio <= 0.7)  $kesimpulan = "⚠️ <b>DISTRIBUTION</b>";
    else                    $kesimpulan = "⚖️ <b>NETRAL</b>";

    // Helper Format B/M/K
    $formatVal = function ($value) {
        $abs_val = abs($value);
        if ($abs_val >= 1000000000) return round($value / 1000000000, 1) . "B";
        if ($abs_val >= 1000000) return round($value / 1000000, 1) . "M";
        return number_format($value / 1000, 0) . "K";
    };

    // 6. Siapkan Tampilan (Dibatasi TOP 4 sesuai permintaan)
    $top_b = [];
    foreach (array_slice($net_buy_list, 0, 4) as $code => $val) {
        $top_b[] = "🔵$code(" . $formatVal($val) . ")";
    }
    // Jika tidak ada pembeli sama sekali
    if (empty($top_b)) $top_b[] = "-";

    $top_s = [];
    foreach (array_slice($net_sell_list, 0, 4) as $code => $val) {
        $top_s[] = "🔴$code(" . $formatVal($val) . ")";
    }
    if (empty($top_s)) $top_s[] = "-";

    // Deteksi Ritel yang aktif (Top 4 Ritel saja)
    $ritel_active = [];
    foreach ($net_data as $code => $val) {
        if (in_array($code, $ritel_codes) && abs($val) >= 1000000) {
            $ritel_active[$code] = $val;
        }
    }
    arsort($ritel_active);

    $ritel_details = [];
    foreach (array_slice($ritel_active, 0, 4) as $r => $v) {
        $icon = ($v < 0) ? "🔴" : "🔵";
        $ritel_details[] = $icon . $r . ":" . $formatVal(abs($v));
    }

    $net_ritel_total = $ritel_buy_total - $ritel_sell_total;
    $ritel_summary = ($net_ritel_total < 0)
        ? "🏃 <b>Ritel Net Out: " . $formatVal(abs($net_ritel_total)) . "</b>"
        : "🛒 <b>Ritel Net In: " . $formatVal($net_ritel_total) . "</b>";

    return [
        "kesimpulan"   => $kesimpulan,
        "top_buy"      => implode(" ", $top_b),
        "top_sell"     => implode(" ", $top_s),
        "footer_ritel" => "\n" . $ritel_summary . "\n<code>" . (empty($ritel_details) ? "Tidak ada aktivitas ritel besar" : implode(" ", $ritel_details)) . "</code>\n🎮 Pengendali: " . $pengendali
    ];
}
