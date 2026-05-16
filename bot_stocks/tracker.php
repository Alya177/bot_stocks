<?php
// tracker.php

/**
 * Menghitung hari bursa berikutnya BERDASARKAN tanggal tertentu (Backfill-friendly)
 *
 * PERBAIKAN KRITIS #1: Tambah batas maksimum iterasi (maks 60 hari ke depan).
 * Sebelumnya loop ini bisa jalan selamanya jika tabel bursa_calendar kosong
 * atau koneksi DB bermasalah di tengah jalan.
 */
function getNextTradingDay($conn, $startDate = null)
{
    $target  = $startDate ? $startDate : date('Y-m-d');
    $attempt = 0;
    $maxAttempts = 60; // Batas aman: tidak mungkin libur > 60 hari berturut-turut

    while ($attempt < $maxAttempts) {
        $attempt++;
        $target = date('Y-m-d', strtotime($target . ' +1 day'));
        $dayNum = date('N', strtotime($target));

        // Lewati Sabtu & Minggu
        if ($dayNum > 5) continue;

        // Cek libur nasional
        $check = $conn->prepare("SELECT id FROM bursa_calendar WHERE holiday_date = ? AND is_active = 1 LIMIT 1");
        $check->bind_param("s", $target);
        $check->execute();
        $isHoliday = $check->get_result()->num_rows > 0;
        $check->close();

        if ($isHoliday) continue;

        // Hari bursa valid ditemukan
        return $target;
    }

    // Jika melebihi batas, lempar exception agar tidak diam-diam return null/salah
    throw new RuntimeException(
        "getNextTradingDay(): Tidak bisa menemukan hari bursa dalam {$maxAttempts} hari ke depan dari {$startDate}. " .
            "Periksa tabel bursa_calendar."
    );
}


function recordNewSignal($conn, $ticker, $current_price, $reason, $rsi, $stoch_k, $vol_for_scoring)
{
    $check = $conn->prepare("SELECT id FROM signal_tracker WHERE ticker = ? AND is_closed = 0");
    $check->bind_param("s", $ticker);
    $check->execute();

    if ($check->get_result()->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO signal_tracker 
            (ticker, trigger_reason, rsi_value, stoch_k_value, vol_ratio, open_price, close_price, signal_date, day_count, is_closed) 
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 0)");

        // Urutan: ticker(s), reason(s), rsi(d), stoch(d), vol(d), open(d), close(d)
        $stmt->bind_param("ssddddd", $ticker, $reason, $rsi, $stoch_k, $vol_for_scoring, $current_price, $current_price);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;

            // CATATAN: UPDATE ini masih aman karena $new_id adalah integer dari insert_id,
            // bukan input dari luar — tidak ada risiko injeksi di sini.
            $conn->query("UPDATE signal_tracker SET parent_id = $new_id WHERE id = $new_id");
            echo "LOG: Sinyal $ticker dicatat ke Tracker (Day 1).\n";
        }
        $stmt->close();
    }
    $check->close();
}


/**
 * Menutup hari berjalan dan menyiapkan baris baru
 *
 * PERBAIKAN KRITIS #2: Validasi timestamp Yahoo sebelum memakai harga penutupan.
 * Sebelumnya end($clean_prices) langsung dipakai tanpa cek tanggal —
 * jika saham suspend/tidak diperdagangkan, harga hari sebelumnya ikut tersimpan
 * sebagai harga hari ini (stale price).
 *
 * PERBAIKAN KRITIS #3: UPDATE close_price pakai prepared statement.
 * Sebelumnya: $conn->query("UPDATE ... close_price = $final_close WHERE id = $id_sekarang")
 * Meski $final_close adalah float hasil kalkulasi (bukan input user), praktik ini
 * berbahaya jika pola yang sama ditiru di tempat lain — seragamkan ke prepared statement.
 */
function processEndOfDay($conn)
{
    // Ambil SEMUA yang is_closed = 0 tanpa filter CURDATE agar data macet ikut terproses
    $sql    = "SELECT id, ticker, parent_id, day_count, trigger_reason, signal_date FROM signal_tracker WHERE is_closed = 0 ORDER BY RAND()";
    $result = $conn->query($sql);
    $total_antrian = $result->num_rows;

    if ($total_antrian == 0) {
        echo "📭 Tidak ada antrian tracker.\n";
        return;
    }

    echo "🚀 LOG: Memproses $total_antrian emiten...\n";

    if (function_exists('sendTelegram')) {
        sendTelegram("🚀 <b>Tracker Started</b>\nMemproses <b>$total_antrian</b> emiten (Backfill Mode).");
    }

    $success_count = 0;
    $failed_count  = 0;

    while ($row = $result->fetch_assoc()) {
        $ticker             = $row['ticker'];
        $id_sekarang        = $row['id'];
        $day_count          = $row['day_count'];
        $parent_id          = $row['parent_id'];
        $current_signal_date = $row['signal_date']; // Tanggal record di DB

        echo "Sync $ticker ($current_signal_date)... ";

        // KRUSIAL: Hitung tanggal besok berdasarkan tanggal record ini, bukan berdasarkan "hari ini"
        // Dibungkus try-catch karena sekarang getNextTradingDay() bisa throw exception
        try {
            $next_date = getNextTradingDay($conn, $current_signal_date);
        } catch (RuntimeException $e) {
            echo "❌ ERROR getNextTradingDay: " . $e->getMessage() . "\n";
            $failed_count++;
            continue;
        }

        $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $url   = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?interval=1d&range=5d";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_HTTPHEADER     => ["Accept: */*", "Connection: keep-alive"],
            CURLOPT_USERAGENT      => $agent,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $res      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 429) {
            $msg_limit = "🛑 <b>RATE LIMIT!</b>\nTicker: <b>$ticker</b>\nTidur 10 menit...";
            if (function_exists('sendTelegram')) sendTelegram($msg_limit);
            sleep(600);
            continue;
        }

        $final_close = 0;

        if ($httpCode == 200 && $res) {
            $json   = json_decode($res, true);
            $result_chart = $json['chart']['result'][0] ?? null;

            if ($result_chart) {
                $timestamps = $result_chart['timestamp'] ?? [];
                $raw_prices = $result_chart['indicators']['quote'][0]['close'] ?? [];

                // --- PERBAIKAN #2: Cocokkan timestamp dengan tanggal signal ---
                // Cari indeks data yang tanggalnya sesuai dengan $current_signal_date
                // agar tidak memakai harga hari lain (stale price saat saham suspend)
                $matched_close = null;

                foreach ($timestamps as $i => $ts) {
                    $ts_date = date('Y-m-d', $ts);
                    if ($ts_date === $current_signal_date) {
                        $val = $raw_prices[$i] ?? null;
                        if (!is_null($val) && $val > 0) {
                            $matched_close = (float)$val;
                        }
                        break;
                    }
                }

                // Fallback: jika tanggal exact tidak ketemu (misal data Yahoo terlambat),
                // gunakan harga terakhir yang valid — tapi log agar bisa diaudit
                if ($matched_close === null) {
                    $clean_prices = array_filter($raw_prices, fn($v) => !is_null($v) && $v > 0);
                    if (!empty($clean_prices)) {
                        $matched_close = end($clean_prices);
                        $last_ts_date  = date('Y-m-d', end($timestamps));
                        echo "[WARN: tanggal tidak cocok, pakai harga $last_ts_date sebagai fallback] ";
                    }
                }

                if ($matched_close !== null) {
                    $final_close = $matched_close;
                }
            }
        }

        if ($final_close > 0) {
            // --- PERBAIKAN #3: Prepared statement untuk UPDATE close_price ---
            $stmt_upd = $conn->prepare("UPDATE signal_tracker SET close_price = ?, is_closed = 1 WHERE id = ?");
            $stmt_upd->bind_param("di", $final_close, $id_sekarang);
            $stmt_upd->execute();
            $stmt_upd->close();

            // Ambil alasan trigger untuk menentukan durasi pelacakan
            $reason = (string)($row['trigger_reason'] ?? '');

            // LOGIKA DURASI DINAMIS BERDASARKAN STRATEGI
            if (stripos($reason, 'INFOA1') !== false || stripos($reason, 'STRATEGI_A1') !== false || stripos($reason, 'SQUEEZE') !== false) {
                $max_track_days = 10; // Strategi Akumulasi & Squeeze
            } elseif (stripos($reason, 'WYCKOFF_SPRING') !== false) {
                $max_track_days = 7;  // Strategi Jebakan Bandar
            } elseif (stripos($reason, 'BLUE_SKY') !== false) {
                $max_track_days = 15; // Strategi Trending
            } elseif (stripos($reason, 'RUBBER_BAND') !== false) {
                $max_track_days = 3;  // Strategi Rebound Cepat
            } else {
                $max_track_days = 5;  // Standar (MACD GC, STOCH GC)
            }

            if ($day_count < $max_track_days) {
                $next_day = $day_count + 1;
                $stmt = $conn->prepare("INSERT INTO signal_tracker (parent_id, ticker, trigger_reason, day_count, signal_date, open_price, close_price, is_closed) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->bind_param("issisdd", $parent_id, $ticker, $reason, $next_day, $next_date, $final_close, $final_close);
                $stmt->execute();
                $stmt->close();
                echo "✅ Success. Next: $next_date (Day $next_day)\n";
            } else {
                echo "✅ Success (Final Day).\n";
            }

            $success_count++;
        } else {
            $failed_count++;
            echo "❌ FAILED ($httpCode).\n";
        }

        $jeda = rand(15, 25);
        echo "⏳ Wait $jeda s...\n";
        sleep($jeda);
    }

    $laporan = "🏁 <b>Tracker Finished</b>\n" .
        "━━━━━━━━━━━━━━\n" .
        "✅ Sukses: <b>$success_count</b>\n" .
        "❌ Gagal: <b>$failed_count</b>";

    if (function_exists('sendTelegram')) sendTelegram($laporan);
}
