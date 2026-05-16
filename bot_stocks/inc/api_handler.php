<?php
// =============================================================================
// PATCH #3 — inc/api_handler.php
// MASALAH : exit() saat 429 membunuh proses dan meninggalkan flock tidak terlepas.
//           Cron berikutnya gagal masuk dengan "Akses Ditolak: Script masih berjalan."
// SOLUSI  : Ganti exit() dengan return ['error' => 'RATE_LIMIT_429', ...]
//           Scanner.php menangkap flag ini dan melakukan break bersih dari loop.
// =============================================================================

function getHistory($ticker)
{
    // --- KONFIGURASI ISTIRAHAT ---
    $statusFile = dirname(__FILE__) . '/yahoo_block_status.txt';
    $istirahatDetik = 3600; // 1 Jam

    // Cek apakah sedang dalam masa hukuman
    if (file_exists($statusFile)) {
        $waktuBlokir = filemtime($statusFile);
        $selisih = time() - $waktuBlokir;
        $sisaWaktu = $istirahatDetik - $selisih;

        if ($sisaWaktu > 0) {
            $menit = ceil($sisaWaktu / 60);
            echo "⏳ [OFFLINE] Masih dalam masa cooldown Yahoo Finance ($menit menit lagi). Skip ticker.\n";

            // ✅ PERBAIKAN: kembalikan flag, jangan exit() agar flock tetap terlepas
            return [
                'close'     => [],
                'high'      => [],
                'low'       => [],
                'volume'    => [],
                'timestamp' => [],
                'error'     => 'RATE_LIMIT_COOLDOWN',
            ];
        } else {
            @unlink($statusFile);
        }
    }

    $agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    ];

    $threeHourBlock = floor(date('H') / 3);
    $seed           = $ticker . date('Ymd') . $threeHourBlock;
    $index          = abs(crc32($seed)) % count($agents);
    $consistentAgent = $agents[$index];

    $cookieDir  = dirname(__FILE__) . '/cookies';
    if (!is_dir($cookieDir)) @mkdir($cookieDir, 0777, true);
    $cookieFile = $cookieDir . '/' . strtolower(str_replace('.JK', '', $ticker)) . '.txt';

    if (file_exists($cookieFile)) {
        $fileTimeBlock = floor(date('H', filemtime($cookieFile)) / 3);
        if ($fileTimeBlock != $threeHourBlock) @unlink($cookieFile);
    }

    $headers = [
        "Accept: */*",
        "Connection: keep-alive",
        "Upgrade-Insecure-Requests: 1"
    ];

    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?interval=1d&range=1y";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, $consistentAgent);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $res       = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // =========================================================================
    // PERBAIKAN LOGIKA ERROR
    // =========================================================================

    // 1. Rate Limit (429) — JANGAN exit(), kembalikan flag agar caller bisa break bersih
    if ($httpCode == 429) {
        file_put_contents($statusFile, "Rate Limit Hit at " . date('Y-m-d H:i:s'));
        echo "🛑 RATE LIMIT (429) Detected. Menulis cooldown marker dan menghentikan loop.\n";

        // ✅ Return flag khusus — scanner.php wajib menangkap ini dengan break
        return [
            'close'     => [],
            'high'      => [],
            'low'       => [],
            'volume'    => [],
            'timestamp' => [],
            'error'     => 'RATE_LIMIT_429',
        ];
    }

    // 2. Error umum lainnya (404, 500, dsb) — cukup skip emiten ini
    if ($httpCode !== 200) {
        echo "DEBUG: HTTP CODE: $httpCode | ERROR: $curlError\n";
        return ['close' => [], 'high' => [], 'low' => [], 'volume' => [], 'timestamp' => []];
    }

    $data   = json_decode($res, true);
    $result = $data['chart']['result'][0] ?? null;
    if (!$result) return ['close' => []];

    $quote     = $result['indicators']['quote'][0] ?? [];
    $cleanData = [];

    $keys = ['open', 'close', 'high', 'low', 'volume'];

    foreach ($keys as $key) {
        $rawValues = $quote[$key] ?? [];
        $lastValid = 0;

        foreach ($rawValues as $v) {
            if (is_numeric($v)) {
                $lastValid = $v;
                break;
            }
        }

        foreach ($rawValues as $val) {
            if (is_numeric($val)) {
                $fVal      = (float)$val;
                $cleanData[$key][] = $fVal;
                $lastValid = $fVal;
            } else {
                $cleanData[$key][] = (float)$lastValid;
            }
        }
    }

    if (isset($result['timestamp']) && count($result['timestamp']) > 1) {
        if ($result['timestamp'][0] > end($result['timestamp'])) {
            $cleanData['open']   = array_reverse($cleanData['open']);
            $cleanData['high']   = array_reverse($cleanData['high']);
            $cleanData['low']    = array_reverse($cleanData['low']);
            $cleanData['close']  = array_reverse($cleanData['close']);
            $cleanData['volume'] = array_reverse($cleanData['volume']);
        }
    }

    return [
        'open'      => $cleanData['open']   ?? [],
        'close'     => $cleanData['close']  ?? [],
        'high'      => $cleanData['high']   ?? [],
        'low'       => $cleanData['low']    ?? [],
        'volume'    => $cleanData['volume'] ?? [],
        'timestamp' => $result['timestamp'] ?? [],
    ];
}
