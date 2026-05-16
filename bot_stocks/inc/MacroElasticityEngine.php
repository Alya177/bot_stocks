<?php
// /inc/MacroElasticityEngine.php

class MacroElasticityEngine
{
    /**
     * Menghitung skor kekuatan magnitudo berdasarkan Standard Deviasi (Sigma Score)
     */
    private static function calculateSigmaScore($current_pct, $history_closes)
    {
        // Bersihkan data dari nilai <= 0 (saham gocap mati atau error data)
        $history_closes = array_values(array_filter($history_closes, fn($v) => $v > 0));
        $count = count($history_closes);
        if ($count < 20) return 0;

        // Hitung perubahan persentase secara aman tanpa pembagi nol/satu palsu
        $changes = [];
        for ($i = 1; $i < $count; $i++) {
            $prev = $history_closes[$i - 1];
            $changes[] = (($history_closes[$i] - $prev) / $prev) * 100;
        }

        $avg_change = array_sum($changes) / count($changes);
        $variance = 0;
        foreach ($changes as $ch) {
            $variance += pow(($ch - $avg_change), 2);
        }
        $std_dev = sqrt($variance / count($changes));

        if ($std_dev == 0) return 0;
        $ratio = abs($current_pct) / $std_dev;

        // Peringkat Sigma (Magnitudo Kejutan)
        if ($ratio >= 2.5)      $score = 3; // Kejutan Ekstrem (Black Swan)
        elseif ($ratio >= 1.2)  $score = 2; // Pergerakan Kuat (Trend Change)
        else                    $score = 1; // Fluktuasi Wajar (Noise)

        return ($current_pct < 0) ? -$score : $score;
    }

    /**
     * Menganalisis pijakan tren menggunakan Z-Score (vs MA-20)
     */
    private static function analyzeZScore($history)
    {
        $count = count($history);
        if ($count < 20) return ['z' => 0];

        $current_price = end($history);
        $slice = array_slice($history, -20);
        $ma20 = array_sum($slice) / 20;

        $variance = 0;
        foreach ($slice as $p) $variance += pow($p - $ma20, 2);
        $std_dev = sqrt($variance / 20);

        $z_score = ($std_dev > 0) ? ($current_price - $ma20) / $std_dev : 0;
        return ['z' => $z_score];
    }

    /**
     * CORE ENGINE: Matriks Keputusan Berdasarkan Trade Role & Sektor
     */
    public static function checkFeasibility($ticker, $sector, $trade_role, $comm_data, $usd_data)
    {
        // 1. FILTER DOMESTIK: Jika bukan bank dan role domestik, lewati beban komputasi kurs
        if ($trade_role === 'DOMESTIC' && $sector !== 'Financial Services') {
            return ['decision' => 'PROCEED', 'desc' => 'Emiten Domestik: Stabil terhadap fluktuasi global.'];
        }

        if (empty($usd_data)) return ['decision' => 'PROCEED', 'desc' => 'Data Kurs tidak tersedia.'];

        // 2. Kalkulasi Data Kurs (Universal untuk non-domestic)
        $u_now = end($usd_data);
        $u_prev = $usd_data[count($usd_data) - 2];
        $u_pct = (($u_now - $u_prev) / $u_prev) * 100;
        $u_sigma = self::calculateSigmaScore($u_pct, $usd_data);
        $u_z = self::analyzeZScore($usd_data);

        // 3. Kalkulasi Data Komoditas (Jika tersedia)
        $c_sigma = 0;
        $c_z = ['z' => 0];
        $c_pct = 0;
        if (!empty($comm_data) && count($comm_data) >= 2) {
            $c_now = end($comm_data);
            $c_prev = $comm_data[count($comm_data) - 2];
            $c_pct = (($c_now - $c_prev) / $c_prev) * 100;
            $c_sigma = self::calculateSigmaScore($c_pct, $comm_data);
            $c_z = self::analyzeZScore($comm_data);
        }

        $decision = "PROCEED";
        $desc = "Kondisi makro dalam toleransi.";

        // --- MATRIKS KEPUTUSAN ADAPTIF ---

        // A. Kelompok Eksportir & Proksi USD (ADRO, ESSA, BULL, INCO, dll)
        if ($trade_role === 'EXPORT') {
            $net_sigma = $c_sigma + $u_sigma;

            // Veto 1: Komoditas hancur (Z-Score tren mingguan patah)
            if ($c_sigma <= -3 || $c_z['z'] < -2.0) {
                $decision = "VETO_HALT";
                $desc = "❌ VETO: Harga komoditas utama hancur/tren patah.";
            }
            // Veto 2: Rupiah menguat terlalu ekstrem (Dolar ambruk merugikan eksportir)
            elseif ($u_sigma <= -3 || $u_z['z'] < -2.0) {
                $decision = "VETO_HALT";
                $desc = "⚠️ VETO: Kurs USD anjlok, menekan nilai pendapatan ekspor.";
            }
            // Skenario 1: Buffering (Komoditas turun sedikit, USD naik kuat) -> Masih untung di Rupiah
            elseif ($c_sigma < 0 && $u_sigma >= 2) {
                $decision = "PROCEED_WITH_CAUTION";
                $desc = "⚖️ BUFFERED: Penurunan barang tertutup penguatan Kurs USD.";
            }
            // Skenario 2: Golden Path (Dolar naik & Komoditas naik)
            elseif ($c_sigma >= 1 && $u_sigma >= 1) {
                $decision = "PROCEED_ACCUMULATION";
                $desc = "🚀 GOLDEN PATH: Kurs & Komoditas sinergi positif (Revenue Booster).";
            }
        }

        // B. Kelompok Importir & Retail (ICBP, UNVR, MAPI, ACES)
        elseif ($trade_role === 'IMPORT') {
            // Veto: Rupiah depresi (USD naik liar) -> Biaya impor/COGS membengkak
            if ($u_sigma >= 2.5 || $u_z['z'] > 1.8) {
                $decision = "VETO_HALT";
                $desc = "❌ VETO: Kurs USD terlalu mahal, risiko tekanan margin laba.";
            }
            // Skenario: Rupiah menguat (Bad for export, but Good for import)
            elseif ($u_sigma <= -2) {
                $decision = "PROCEED_ACCUMULATION";
                $desc = "💎 KURS GAIN: Penguatan Rupiah menurunkan beban impor barang.";
            }
        }

        // C. Kelompok Perbankan (Financial Services)
        elseif ($sector === 'Financial Services') {
            // Bank butuh stabilitas. Gejolak Sigma 3 (Shock) biasanya memicu Outflow modal.
            if (abs($u_sigma) >= 3 || abs($u_z['z']) > 2.0) {
                $decision = "VETO_HALT";
                $desc = "⚠️ VETO BANK: Volatilitas kurs ekstrem berisiko pada stabilitas dana asing.";
            }
        }

        return [
            'decision' => $decision,
            'desc'     => $desc,
            'metrics'  => [
                'c_sigma' => $c_sigma,
                'u_sigma' => $u_sigma,
                'net_pct' => round($c_pct + $u_pct, 2)
            ]
        ];
    }
}
