<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Helpers;

/**
 * PIIサニタイズ（個人情報やシークレット情報の除去）を行うヘルパークラス
 */
final class PiiSanitizer
{
    /**
     * contextをサニタイズする（許可されたキーのみ抽出し、URLをクリーニング）
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $allowedKeys = ['environment', 'timestamp', 'url', 'user_id'];
        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (! isset($context[$key])) {
                continue;
            }

            $value = $context[$key];

            // URLのクエリ/フラグメントを除去
            if ($key === 'url' && is_string($value)) {
                $parsed = parse_url($value);
                if ($parsed !== false) {
                    $value = sprintf(
                        '%s://%s%s',
                        $parsed['scheme'] ?? 'https',
                        $parsed['host'] ?? '',
                        $parsed['path'] ?? '/',
                    );
                }
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * トレースをサニタイズする（PII/シークレットマスク＆長さ上限）
     */
    public function sanitizeTrace(string $trace): string
    {
        // Authorization Bearerヘッダをマスク
        $trace = (string) preg_replace(
            '/(Authorization\s*:\s*Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i',
            '$1[BEARER_TOKEN_MASKED]',
            $trace,
        );

        // メールアドレスをマスク
        $trace = (string) preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            '[EMAIL_MASKED]',
            $trace,
        );

        // JWTっぽいトークン（header.payload.signature）をマスク
        $trace = (string) preg_replace(
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            '[JWT_MASKED]',
            $trace,
        );

        // 一般的なAPIキー形式（例: Stripe）をマスク
        $trace = (string) preg_replace(
            '/\bsk_(?:live|test)_[A-Za-z0-9]{8,}\b/',
            '[API_KEY_MASKED]',
            $trace,
        );

        // トークンっぽい値（32文字以上のhex文字列）をマスク
        $trace = (string) preg_replace('/\b[0-9a-fA-F]{32,}\b/', '[TOKEN_MASKED]', $trace);

        // UUIDをマスク
        $trace = (string) preg_replace(
            '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/',
            '[UUID_MASKED]',
            $trace,
        );

        // 長さ上限（10000文字）
        if (strlen($trace) > 10000) {
            $trace = substr($trace, 0, 10000)."\n... (truncated)";
        }

        return $trace;
    }
}
