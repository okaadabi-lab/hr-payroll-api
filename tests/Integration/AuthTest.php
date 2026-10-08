<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

final class AuthTest extends IntegrationTestCase
{
    public function testLoginReturnsShortLivedAccessTokenAndOpaqueRefreshToken(): void
    {
        $tokens = $this->login('hr@example.test');

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(900, $tokens['expires_in']);
        self::assertSame('hr', $tokens['user']['role']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $tokens['refresh_token']);

        $stored = $this->db->fetchOne('SELECT token_hash FROM refresh_tokens');
        $expectedHash = hash('sha256', $tokens['refresh_token']);
        self::assertSame($expectedHash, $stored['token_hash'] ?? null, 'Only the hash is stored');
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameError(): void
    {
        $wrongPassword = $this->post('/api/v1/auth/login', ['email' => 'hr@example.test', 'password' => 'nope']);
        $unknown = $this->post('/api/v1/auth/login', ['email' => 'ghost@example.test', 'password' => 'nope']);

        $this->assertStatus(401, $wrongPassword);
        $this->assertStatus(401, $unknown);
        self::assertSame($this->json($wrongPassword)['detail'], $this->json($unknown)['detail']);
        self::assertSame('application/problem+json', $wrongPassword->getHeaderLine('Content-Type'));
    }

    public function testLoginIsRateLimitedPerEmailAndIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/v1/auth/login', ['email' => 'hr@example.test', 'password' => 'wrong']);
        }

        $blocked = $this->post('/api/v1/auth/login', ['email' => 'hr@example.test', 'password' => 'Password123!']);
        $this->assertStatus(429, $blocked);
        self::assertGreaterThan(0, (int) $blocked->getHeaderLine('Retry-After'));

        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'Password123!',
        ]), 'other accounts from the same IP are unaffected');

        $this->advanceClock('+16 minutes');
        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'hr@example.test',
            'password' => 'Password123!',
        ]));
    }

    public function testLoginValidatesInput(): void
    {
        $response = $this->post('/api/v1/auth/login', ['email' => 'not-an-email']);

        $this->assertStatus(422, $response);
        $fields = array_column($this->json($response)['errors'], 'field');
        self::assertSame(['email', 'password'], $fields);
    }

    public function testRefreshRotatesTheToken(): void
    {
        $first = $this->login('hr@example.test');

        $response = $this->post('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']]);
        $this->assertStatus(200, $response);
        $second = $this->json($response);

        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        $me = $this->request('GET', '/api/v1/me', null, null, ['Authorization' => 'Bearer ' . $second['access_token']]);
        $this->assertStatus(200, $me);
        self::assertSame('hr@example.test', $this->json($me)['data']['email']);
    }

    public function testReusingARotatedRefreshTokenRevokesTheWholeFamily(): void
    {
        $first = $this->login('hr@example.test');
        $second = $this->json($this->post('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']]));

        $replay = $this->post('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']]);
        $this->assertStatus(401, $replay);
        self::assertSame('refresh_token_reused', $this->json($replay)['code']);

        $legitimate = $this->post('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']]);
        $this->assertStatus(401, $legitimate);
        self::assertSame(1, $this->auditCount('auth.refresh_token_reused'));
    }

    public function testReuseDetectionDoesNotAffectOtherLogins(): void
    {
        $sessionA = $this->login('hr@example.test');
        $sessionB = $this->login('hr@example.test');
        $this->post('/api/v1/auth/refresh', ['refresh_token' => $sessionA['refresh_token']]);
        $this->post('/api/v1/auth/refresh', ['refresh_token' => $sessionA['refresh_token']]);

        $this->assertStatus(200, $this->post('/api/v1/auth/refresh', ['refresh_token' => $sessionB['refresh_token']]));
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $tokens = $this->login('hr@example.test');
        $this->advanceClock('+31 days');

        $response = $this->post('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertStatus(401, $response);
        self::assertSame('invalid_refresh_token', $this->json($response)['code']);
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $tokens = $this->login('hr@example.test');

        $this->assertStatus(204, $this->post('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']]));
        $this->assertStatus(401, $this->post('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));
    }

    public function testAccessTokenExpiresAfterFifteenMinutes(): void
    {
        $token = $this->tokenFor('hr');
        $this->advanceClock('+15 minutes 1 second');

        $response = $this->request('GET', '/api/v1/me', null, null, ['Authorization' => "Bearer {$token}"]);
        $this->assertStatus(401, $response);
        self::assertSame('invalid_token', $this->json($response)['code']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        [$header, $payload, $signature] = explode('.', $this->tokenFor('budi'));
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['role'] = 'admin';
        $forgedPayload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
        $forged = "{$header}.{$forgedPayload}.{$signature}";

        $response = $this->request('GET', '/api/v1/me', null, null, ['Authorization' => "Bearer {$forged}"]);
        $this->assertStatus(401, $response);
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $this->db->run('UPDATE users SET is_active = 0 WHERE email = ?', ['hr@example.test']);

        $this->assertStatus(401, $this->post('/api/v1/auth/login', [
            'email' => 'hr@example.test',
            'password' => 'Password123!',
        ]));
    }
}
