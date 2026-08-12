<?php

namespace CodeWithDiki\PaymentModule\Supports\Doku;

use Illuminate\Http\Client\RequestException;
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

    /**
     * Path, headers and body of the most recent post(), so the caller can persist what it
     * actually sent when DOKU rejects the call. Instance state: one client per processor,
     * one call per processor run.
     *
     * @var array{path?: string, headers?: array<string, string>, body?: array<mixed>}
     */
    public array $lastRequest = [];

    /**
     * @param  string|null  $channelId  CHANNEL-ID header, omitted when null. Direct Debit is the
     *                                  one family of SNAP endpoints that must not carry it.
     * @param  array<string, string>  $extraHeaders  Endpoint specific headers (X-IP-ADDRESS, ...).
     *                                               They sit outside the signature, which covers
     *                                               only method, path, token, body and timestamp.
     */
    public function post(string $path, array $body, ?string $channelId = null, array $extraHeaders = []): Response
    {
        // The signature is computed over the exact bytes we send. Encoding once and
        // passing the same string to withBody() keeps the two from ever diverging.
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = $this->timestamp();

        try {
            $token = $this->accessToken();
        } catch (RequestException $e) {
            // A rejected token request is still DOKU's answer to this call. Returning it as
            // the call's own response keeps the caller on its normal failure path — which
            // records the body — instead of unwinding with nothing logged at all.
            $this->lastRequest = [
                'path' => self::TOKEN_PATH,
                'headers' => [
                    'X-CLIENT-KEY' => $this->clientId(),
                    'X-TIMESTAMP' => $timestamp,
                ],
                'body' => ['grantType' => 'client_credentials'],
            ];

            return $e->response;
        }

        $headers = array_filter([
            'Authorization' => 'Bearer '.$token,
            'X-PARTNER-ID' => $this->clientId(),
            'X-EXTERNAL-ID' => $this->externalId(),
            'X-TIMESTAMP' => $timestamp,
            'CHANNEL-ID' => $channelId,
            'X-SIGNATURE' => self::symmetricSignature(
                self::stringToSign('POST', $path, $token, $json, $timestamp),
                $this->clientSecret(),
            ),
            ...$extraHeaders,
        ], fn (?string $value) => $value !== null && $value !== '');

        // The bearer token is a live 15-minute credential; never let it reach a stored log.
        $this->lastRequest = [
            'path' => $path,
            'headers' => ['Authorization' => 'Bearer [redacted]'] + $headers,
            'body' => $body,
        ];

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeaders($headers)
            ->withBody($json, 'application/json')
            ->post($path);
    }

    /**
     * Response worth storing. DOKU answers a bad signature with a JSON body, but an
     * upstream 401 can arrive as HTML or empty — keep the status and raw body then,
     * so "no log at all" is never the outcome of a failed call.
     *
     * @return array<mixed>
     */
    public static function describe(Response $response): array
    {
        return $response->json() ?? [
            'status' => $response->status(),
            'body' => $response->body(),
        ];
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
