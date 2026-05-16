<?php
// daily_closing.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jakarta');
set_time_limit(0); // Memastikan script tidak timeout meski jalan 1 jam
ignore_user_abort(true); // Tetap jalan meskipun koneksi SSH terputus

// --- 0. INITIAL RANDOM DELAY (Ghost Mode) ---
// Menunda eksekusi awal antara 10 detik sampai 3 menit (180 detik)
// Agar Cron tidak selalu memukul API di detik yang sama setiap hari.
$initial_delay = rand(10000000, 180000000);
echo "Menunda eksekusi awal selama " . ($initial_delay / 1000000) . " detik...\n";
usleep($initial_delay);

// 1. Load semua dependensi (Koneksi & Fungsi)
include 'config.php';
include 'functions.php';
include 'tracker.php';

// 2. Filter Waktu & Hari
$today = date('Y-m-d');
$hari  = date('N');

// Filter Weekend
if ($hari > 5) {
    die("Sistem Standby: Weekend.\n");
}

// 3. Filter Libur Bursa (Menggunakan koneksi dari config.php)
$check_h = $conn->prepare("SELECT id FROM bursa_calendar WHERE holiday_date = ? AND is_active = 1 LIMIT 1");
$check_h->bind_param("s", $today);
$check_h->execute();
$result_h = $check_h->get_result();

if ($result_h->num_rows > 0) {
    $check_h->close();
    die("Sistem Standby: Hari ini Libur Bursa.\n");
}
$check_h->close();

// 4. Eksekusi Utama
echo "Memulai sinkronisasi harga penutupan untuk tanggal $today...\n";
processEndOfDay($conn);
echo "Semua data tracker telah divalidasi dan di-rollover.\n";
