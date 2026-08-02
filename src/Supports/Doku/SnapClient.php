<?php

namespace CodeWithDiki\PaymentModule\Supports\Doku;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Authentication for DOKU's SNAP APIs, shared by the payment processor
 * (Supports\PaymentMethod\Doku) and the payout processor (Supports\Disbursement\DokuKirim).
 *
 * SNAP uses two different signatures:
 *  - asymmetric (SHA256withRSA over "clientId|timestamp") to obtain a B2B access token
 *  - symmetric (HMAC-SHA512 over a five-part string) for every other call
 *
 * @see https://developers.doku.com/get-started-with-doku-api/signature-component/snap
 */
class SnapClient
{
    public const TOKEN_PATH = '/authorization/v1/access-token/b2b';

    protected const TOKEN_CACHE_KEY = 'payment-module.doku.access_token';

    /** Seconds subtracted from the token lifetime so an in-flight request never races the expiry */
    protected const TOKEN_EXPIRY_MARGIN = 60;

    public function post(string $path, array $body, string $channelId): Response
    {
        // The signature is computed over the exact bytes we send. Encoding once and
        // passing the same string to withBody() keeps the two from ever diverging.
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = $this->timestamp();
        $token = $this->accessToken();

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'X-PARTNER-ID' => $this->clientId(),
                'X-EXTERNAL-ID' => $this->externalId(),
                'X-TIMESTAMP' => $timestamp,
                'CHANNEL-ID' => $channelId,
                'X-SIGNATURE' => self::symmetricSignature(
                    self::stringToSign('POST', $path, $token, $json, $timestamp),
                    $this->clientSecret(),
                ),
            ])
            ->withBody($json, 'application/json')
            ->post($path);
    }

    /**
     * B2B access token, cached for its advertised lifetime (DOKU issues 15 minute tokens).
     */
    public function accessToken(): string
    {
        if ($cached = Cache::get(self::TOKEN_CACHE_KEY)) {
            return (string) $cached;
        }

        $timestamp = $this->timestamp();

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-CLIENT-KEY' => $this->clientId(),
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $this->asymmetricSignature($this->clientId().'|'.$timestamp),
            ])
            ->post(self::TOKEN_PATH, ['grantType' => 'client_credentials'])
            ->throw();

        $token = (string) $response->json('accessToken');

        Cache::put(
            self::TOKEN_CACHE_KEY,
            $token,
            max(self::TOKEN_EXPIRY_MARGIN, (int) $response->json('expiresIn', 900) - self::TOKEN_EXPIRY_MARGIN),
        );

        return $token;
    }

    /**
     * stringToSign = METHOD:path:accessToken:lowercase(hex(sha256(body))):timestamp
     *
     * Used for both outgoing transactional requests and for verifying the notifications
     * DOKU sends us, where `$path` is our own webhook path instead of DOKU's.
     */
    public static function stringToSign(string $method, string $path, string $accessToken, string $body, string $timestamp): string
    {
        return implode(':', [
            strtoupper($method),
            $path,
            $accessToken,
            strtolower(hash('sha256', $body)),
            $timestamp,
        ]);
    }

    public static function symmetricSignature(string $stringToSign, string $clientSecret): string
    {
        return base64_encode(hash_hmac('sha512', $stringToSign, $clientSecret, true));
    }

    /**
     * The currently cached token, or an empty string. Used by the notification validator,
     * which must never trigger a token request of its own.
     */
    public static function cachedAccessToken(): string
    {
        return (string) Cache::get(self::TOKEN_CACHE_KEY, '');
    }

    public function baseUrl(): string
    {
        return config('payment-module.doku_is_production')
            ? 'https://api.doku.com'
            : 'https://api-sandbox.doku.com';
    }

    /**
     * DOKU requires a numeric reference that is unique within the same day.
     */
    protected function externalId(): string
    {
        return now()->format('YmdHis').random_int(100000, 999999);
    }

    /**
     * ISO8601 in UTC, the format the Get Token docs spell out explicitly
     * ("2020-09-22T01:51:00Z").
     */
    protected function timestamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * SHA256withRSA over "clientId|timestamp", using the private key whose public half
     * is registered in the DOKU dashboard. Accepts either an inline PEM or a "file://" path.
     */
    protected function asymmetricSignature(string $stringToSign): string
    {
        $key = openssl_pkey_get_private((string) config('payment-module.doku_private_key'));

        if ($key === false) {
            throw new \RuntimeException('payment-module.doku_private_key is not a readable RSA private key.');
        }

        openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    protected function clientId(): string
    {
        return (string) config('payment-module.doku_client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('payment-module.doku_client_secret');
    }
}
