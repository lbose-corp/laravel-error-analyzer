<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Helpers;

/**
 * エラーのフィンガープリント計算とデデュープウィンドウ計算を行うヘルパークラス
 */
final class FingerprintCalculator
{
    /**
     * フィンガープリントを計算する（例外クラス、ファイル、行番号から）
     *
     * @param  string  $exceptionClass  例外クラス名
     * @param  string  $file  発生ファイル
     * @param  int  $line  発生行番号
     */
    public function compute(string $exceptionClass, string $file, int $line): string
    {
        $data = sprintf(
            '%s:%s:%d',
            $exceptionClass,
            $file,
            $line,
        );

        return hash('sha256', $data);
    }

    /**
     * デデュープウィンドウを計算する（指定分単位のバケット）
     *
     * @param  int  $timestamp  Unix timestamp
     * @param  int  $windowMinutes  ウィンドウサイズ（分）
     */
    public function computeDedupeWindow(int $timestamp, int $windowMinutes = 5): int
    {
        return (int) floor($timestamp / ($windowMinutes * 60));
    }
}
