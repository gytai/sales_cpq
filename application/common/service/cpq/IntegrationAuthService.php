<?php

namespace app\common\service\cpq;

use InvalidArgumentException;

/** HMAC/OAuth2 接入契约；不记录或回显 token/secret。 */
class IntegrationAuthService
{
    public function signHmac($body, $timestamp, $secret, $algorithm = 'sha256')
    {
        if (!in_array($algorithm, ['sha256','sha512'], true)) throw new InvalidArgumentException('不支持的 HMAC 算法');
        return hash_hmac($algorithm, (string)$timestamp . '.' . (string)$body, (string)$secret);
    }

    public function verifyHmac($body, $timestamp, $signature, $secret, $algorithm = 'sha256', $maxSkewSeconds = 300, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        if (!ctype_digit((string)$timestamp) || abs($now - (int)$timestamp) > (int)$maxSkewSeconds) return false;
        return hash_equals($this->signHmac($body, $timestamp, $secret, $algorithm), strtolower((string)$signature));
    }

    public function authorizationHeaders(array $config, array $credentials, $body, $timestamp = null)
    {
        $type = (string)($config['auth_type'] ?? 'none');
        if ($type === 'hmac') {
            if (empty($credentials['hmac_secret'])) throw new InvalidArgumentException('HMAC 凭证未配置');
            $timestamp = $timestamp === null ? time() : (int)$timestamp;
            return ['X-CPQ-Timestamp'=>(string)$timestamp, 'X-CPQ-Signature'=>$this->signHmac($body, $timestamp, $credentials['hmac_secret'], $config['hmac_algorithm'] ?? 'sha256')];
        }
        if ($type === 'oauth2') {
            if (empty($credentials['access_token'])) throw new InvalidArgumentException('OAuth2 access_token 未配置或已过期');
            return ['Authorization'=>'Bearer ' . $credentials['access_token']];
        }
        return [];
    }
}
