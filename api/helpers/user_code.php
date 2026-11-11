<?php
// 60Score / STUMPS - Deterministic User Code Generator
// Matches the Dart userCodeForUid() implementation 100%
// Generates stable 10-char codes: '60S-' + 6 uppercase alphanumeric characters.

function userCodeForUid(string $uid): string {
    $fnv = function(string $s, int $seed): int {
        $h = $seed & 0xFFFFFFFF;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            // 32-bit integer multiplication with 0x01000193 (16777619)
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return $h;
    };

    $h1 = $fnv($uid, 0x811C9DC5);
    $h2 = $fnv($uid, 0x9E3779B9);
    $h = ($h1 ^ (($h2 << 1) & 0xFFFFFFFF)) & 0xFFFFFFFF;

    $space = 2176782336; // 36^6
    $v = abs($h) % $space;

    $digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $buf = '';
    for ($i = 0; $i < 6; $i++) {
        $buf .= $digits[$v % 36];
        $v = intdiv($v, 36);
    }

    return '60S-' . $buf;
}
