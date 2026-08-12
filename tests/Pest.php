<?php

use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use CodeWithDiki\PaymentModule\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(TestCase::class)->in(__DIR__);

/*
 * DOKU helpers live here rather than in a test file because both DokuTest and
 * DokuDisbursementTest need them, and phpunit.xml.dist runs tests in random order.
 */

function dokuCredentials(): void
{
    config()->set('payment-module.doku_client_id', 'MCH-0001-1079');
    config()->set('payment-module.doku_client_secret', 'doku-secret');
    config()->set('payment-module.doku_private_key', dokuPrivateKey());
    config()->set('payment-module.doku_qris_terminal_id', 'TERM01');
    config()->set('payment-module.doku_qris_postal_code', '40115');
}

/** A throwaway RSA key, generated once per process, to sign the B2B token request. */
function dokuPrivateKey(): string
{
    static $pem = null;

    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
    }

    return $pem;
}

function dokuFake(array $responses = []): void
{
    Http::fake(array_merge([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response([
            'responseCode' => '2007300',
            'accessToken' => 'token-abc',
            'expiresIn' => 900,
        ]),
    ], $responses));
}

/**
 * Posts a DOKU notification with a real SNAP signature over the exact body bytes.
 * Pass $signature to forge an invalid one.
 */
function dokuPostWebhook(string $uri, array $body, ?string $signature = null): TestResponse
{
    $payload = json_encode($body);
    $timestamp = '2026-08-02T03:00:00Z';

    $signature ??= SnapClient::symmetricSignature(
        SnapClient::stringToSign('POST', $uri, SnapClient::cachedAccessToken(), $payload, $timestamp),
        config('payment-module.doku_client_secret'),
    );

    return test()->call('POST', $uri, [], [], [], [
        'HTTP_X_SIGNATURE' => $signature,
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_PARTNER_ID' => 'MCH-0001-1079',
        'HTTP_X_EXTERNAL_ID' => '12345',
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/**
 * Posts a DOKU Checkout notification, signed the way DOKU documents it.
 * Pass $signature to forge an invalid one, or $clientId to impersonate another merchant.
 */
function dokuPostCheckoutWebhook(string $uri, array $body, ?string $signature = null, ?string $clientId = null): TestResponse
{
    $payload = json_encode($body);
    $requestId = 'req-0001';
    $timestamp = '2026-08-02T03:00:00Z';
    $clientId ??= config('payment-module.doku_client_id');

    $signature ??= 'HMACSHA256='.base64_encode(hash_hmac('sha256', implode("\n", [
        'Client-Id:'.$clientId,
        'Request-Id:'.$requestId,
        'Request-Timestamp:'.$timestamp,
        'Request-Target:'.$uri,
        'Digest:'.base64_encode(hash('sha256', $payload, true)),
    ]), config('payment-module.doku_client_secret'), true));

    return test()->call('POST', $uri, [], [], [], [
        'HTTP_SIGNATURE' => $signature,
        'HTTP_CLIENT_ID' => $clientId,
        'HTTP_REQUEST_ID' => $requestId,
        'HTTP_REQUEST_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}
