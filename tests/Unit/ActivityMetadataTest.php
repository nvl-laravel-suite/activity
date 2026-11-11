<?php

declare(strict_types=1);

use Nvl\Activity\Support\ActivityMetadata;

it('bounds immutable JSON records before persistence', function (): void {
    expect(fn () => ActivityMetadata::record(['large' => str_repeat('a', 65537)], 'context'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ActivityMetadata::record(['number' => INF], 'context'))->toThrow(InvalidArgumentException::class);
});

it('redacts keys that name a credential', function (string $key): void {
    expect(ActivityMetadata::redact([$key => 'value', 'nested' => [$key => 'value']]))
        ->toBe([$key => '[REDACTED]', 'nested' => [$key => '[REDACTED]']]);
})->with([
    'password', 'Password', 'user_password', 'password_confirmation', 'password_hash', 'passwordHash', 'current-password', 'passwords',
    'secret', 'client_secret', 'clientSecret', 'secret_key', 'secretKey', 'secretkey', 'webhook.secret', 'secrets',
    'token', 'api_token', 'accessToken', 'access_token', 'refresh-token', 'remember_token', 'csrftoken', 'XAuthToken', 'tokens', 'token_hash',
    'api_key', 'api-key', 'apikey', 'apiKey', 'APIKey', 'x-api-key', 'api_keys',
    'authorization', 'Authorization', 'proxy-authorization', 'HTTPAuthorization',
    'cookie', 'Cookie', 'set-cookie', 'session_cookie', 'HTTPCookie', 'cookies',
]);

it('keeps keys that only contain or describe a credential word', function (string $key): void {
    expect(ActivityMetadata::redact([$key => 'value']))->toBe([$key => 'value']);
})->with([
    'token_count', 'tokenCount', 'authorization_status', 'secretary_id', 'secretary', 'tokenizer', 'password_changed_at',
    'token_type', 'cookie_length', 'passwordless', 'title', 'state',
]);
