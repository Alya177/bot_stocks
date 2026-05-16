<?php
// /inc/CandlePolice.php

/**
 * CANDLE POLICE - STRATEGY OPTIMIZED
 * Fokus: Deteksi pola reversal 1-3 hari untuk filter Stochastic GC.
 */
class CandlePolice
{
    /**
     * Audit Tekanan Jual Tinggi (Veto Filter)
     * Membuang emiten dengan tekanan jual masif.
     */
    public static function hasHighSellingPressure($open, $high, $low, $close)
    {
        $res = trader_cdlmarubozu($open, $high, $low, $close);
        if ($res && end($res) <= -100) return true;

        $resClose = trader_cdlclosingmarubozu($open, $high, $low, $close);
        if ($resClose && end($resClose) <= -100) return true;

        $c_open  = end($open);
        $c_close = end($close);
        $c_high  = end($high);
        $c_low   = end($low);

        if ($c_close < $c_open) {
            $bodySize   = $c_open - $c_close;
            $totalRange = $c_high - $c_low;
            if ($totalRange > 0 && ($bodySize / $totalRange) > 0.7) return true;
        }
        return false;
    }

    /**
     * Konfirmasi Reversal Bullish (Multi-Day Structure)
     * Mengelompokkan pola berdasarkan jumlah hari (1-3 hari).
     */
    public static function isBullishReversal($open, $high, $low, $close)
    {
        // KATEGORI 1: POLA 3 HARI (Sangat Kuat)
        $p3 = [
            'MorningStar' => trader_cdlmorningstar($open, $high, $low, $close),
            'MorningDojiStar' => trader_cdlmorningdojistar($open, $high, $low, $close),
            'ThreeWhiteSoldiers' => trader_cdl3whitesoldiers($open, $high, $low, $close)
        ];

        foreach ($p3 as $name => $res) {
            if ($res && end($res) > 0) return $name;
        }

        // KATEGORI 2: POLA 2 HARI (Kuat)
        $p2 = [
            'Engulfing' => trader_cdlengulfing($open, $high, $low, $close),
            'Piercing' => trader_cdlpiercing($open, $high, $low, $close),
            'BullishHarami' => trader_cdlharami($open, $high, $low, $close)
        ];

        foreach ($p2 as $name => $res) {
            if ($res && end($res) > 0) return $name;
        }

        // KATEGORI 3: POLA 1 HARI (Konfirmasi Pantulan)
        $p1 = [
            'Hammer' => trader_cdlhammer($open, $high, $low, $close),
            'InvertedHammer' => trader_cdlinvertedhammer($open, $high, $low, $close),
            'DragonflyDoji' => trader_cdldragonflydoji($open, $high, $low, $close)
        ];

        foreach ($p1 as $name => $res) {
            if ($res && end($res) > 0) return $name;
        }

        return false;
    }

    /**
     * TAMBAHKAN INI: Deteksi Keraguan Pasar (Neutral/Caution)
     * Berfungsi untuk merespon peringatan 'Undefined method isIndecision'
     */
    public static function isIndecision($open, $high, $low, $close)
    {
        $doji = trader_cdldoji($open, $high, $low, $close);
        $spinning = trader_cdlspinningtop($open, $high, $low, $close);

        if (($doji && end($doji) != 0) || ($spinning && end($spinning) != 0)) {
            return true;
        }
        return false;
    }

    /**
     * TAMBAHKAN INI: Audit Tekanan Beli (Booster Skor)
     */
    public static function hasHighBuyingPressure($open, $high, $low, $close)
    {
        $marubozu = trader_cdlmarubozu($open, $high, $low, $close);
        if ($marubozu && end($marubozu) >= 100) return true;
        return false;
    }
}
