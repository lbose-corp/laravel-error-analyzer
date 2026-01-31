<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Helpers;

/**
 * AI応答テキストからJSONを抽出するヘルパークラス
 */
final class JsonExtractor
{
    /**
     * JSONテキストを抽出（コードブロックから、または生のJSONから）
     *
     * @param  string  $text  抽出対象のテキスト
     * @return string  抽出されたJSON文字列
     */
    public static function extract(string $text): string
    {
        $trimmed = trim($text);

        // ```json ... ``` ブロックから抽出
        if (preg_match('/```json\s*(.*?)\s*```/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        // 最初の { から最後の } までを抽出
        $firstBrace = strpos($trimmed, '{');
        if ($firstBrace === false) {
            return $trimmed;
        }

        $length = strlen($trimmed);
        $depth = 0;
        $inString = false;
        $escape = false;
        $start = null;

        for ($i = $firstBrace; $i < $length; $i++) {
            $char = $trimmed[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($char === '\\') {
                    $escape = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    return substr($trimmed, $start, $i - $start + 1);
                }
            }
        }

        return $trimmed;
    }
}
