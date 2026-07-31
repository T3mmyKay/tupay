<?php

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use JsonException;

final class ElevatedActionTokenService
{
    public function __construct(private readonly ActionPayloadHasher $hasher)
    {
    }

    /** @param array<string, mixed> $actionPayload */
    public function issue(User $user, array $actionPayload): string
    {
        $now = time();
        $ttl = $this->ttl();
        $jti = (string) Str::uuid();
        $actionHash = $this->hasher->hash($actionPayload);

        $header = $this->encodeJson(['alg' => 'HS256', 'typ' => 'EAT']);
        $payload = $this->encodeJson([
            'jti' => $jti,
            'sub' => (string) $user->getKey(),
            'action_hash' => $actionHash,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $unsignedToken = $header.'.'.$payload;
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $unsignedToken, $this->secret(), true));
        $token = $unsignedToken.'.'.$signature;

        $this->redis()->setex($this->redisKey($jti), $ttl, $user->getKey().'|'.$actionHash);

        return $token;
    }

    /** @param array<string, mixed> $actionPayload */
    public function consume(User $user, array $actionPayload, string $token): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidElevatedActionToken('Elevated action token is malformed.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $unsignedToken = $encodedHeader.'.'.$encodedPayload;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $unsignedToken, $this->secret(), true));

        if (! hash_equals($expectedSignature, $encodedSignature)) {
            throw new InvalidElevatedActionToken('Elevated action token signature is invalid.');
        }

        $header = $this->decodeJson($encodedHeader);
        $payload = $this->decodeJson($encodedPayload);

        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'EAT') {
            throw new InvalidElevatedActionToken('Elevated action token header is invalid.');
        }

        $jti = $payload['jti'] ?? null;
        $subject = $payload['sub'] ?? null;
        $expiresAt = $payload['exp'] ?? null;
        $actionHash = $payload['action_hash'] ?? null;

        if (! is_string($jti) || ! is_string($subject) || ! is_int($expiresAt) || ! is_string($actionHash)) {
            throw new InvalidElevatedActionToken('Elevated action token claims are invalid.');
        }

        if ($expiresAt <= time()) {
            throw new InvalidElevatedActionToken('Elevated action token has expired.');
        }

        $expectedActionHash = $this->hasher->hash($actionPayload);
        if (! hash_equals((string) $user->getKey(), $subject) || ! hash_equals($expectedActionHash, $actionHash)) {
            throw new InvalidElevatedActionToken('Elevated action token does not authorize this exact action.');
        }

        $consumed = $this->redis()->command('getdel', [$this->redisKey($jti)]);
        $expectedStoredValue = $user->getKey().'|'.$expectedActionHash;

        if (! is_string($consumed) || ! hash_equals($expectedStoredValue, $consumed)) {
            throw new InvalidElevatedActionToken('Elevated action token has already been consumed or is invalid.');
        }
    }

    private function secret(): string
    {
        $secret = (string) config('services.eat.signing_key');
        if (strlen($secret) < 32) {
            throw new InvalidElevatedActionToken('EAT_SIGNING_KEY must contain at least 32 characters.');
        }

        return $secret;
    }

    private function ttl(): int
    {
        return max(1, (int) config('services.eat.ttl_seconds', 60));
    }

    private function redisKey(string $jti): string
    {
        return 'eat:'.$jti;
    }

    private function redis(): Connection
    {
        return Redis::connection();
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        try {
            return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InvalidElevatedActionToken('Elevated action token could not be encoded.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        $decoded = $this->base64UrlDecode($value);

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidElevatedActionToken('Elevated action token JSON is invalid.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidElevatedActionToken('Elevated action token JSON must be an object.');
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidElevatedActionToken('Elevated action token encoding is invalid.');
        }

        return $decoded;
    }
}
