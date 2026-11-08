<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\JwtProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-repository token contract (docs/fase-3/CONTRATOS.md §4 and docs/fase-3/contract-token.md).
 *
 * The auth Lambda (oficina-gateway-auth) issues the customer token and THIS application
 * revalidates it locally. Both sides must produce a byte-for-byte identical token for the same
 * inputs; if either side changes the header, the claim order or the base64url encoding, the
 * tokens minted by the Lambda start being rejected here in production.
 *
 * Freezing the secret, the payload and the clock turns that silent production failure into a
 * red test on whichever side drifted. The literal below is the token published in
 * docs/fase-3/contract-token.md — do not regenerate it to make the test pass.
 */
class JwtContractTest extends TestCase
{
    private const SECRET = 'contract-test-secret-do-not-use-in-prod';
    private const IAT    = 1767225600;

    private const EXPECTED_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
        . '.eyJzdWIiOiIxMTExMTExMS0xMTExLTQxMTEtODExMS0xMTExMTExMTExMTEiLCJyb2xlIjoiY3VzdG9tZXIiLCJjcGYiOiI1Mjk5ODIyNDcyNSIsIm5hbWUiOiJNYXJpYSBTb3V6YSIsImlzcyI6Im9maWNpbmEtbWVjYW5pY2EtYXBpIiwiaWF0IjoxNzY3MjI1NjAwLCJleHAiOjE3NjcyMjkyMDB9'
        . '.vhZR6_6jD_NdgJvg9yCm4vAkeMUXNCAyY8oE7pr9Vws';

    /** @return array<string, mixed> */
    private function contractPayload(): array
    {
        // Order matters: sub, role, cpf, name — then iss, iat, exp appended by generate().
        return [
            'sub'  => '11111111-1111-4111-8111-111111111111',
            'role' => 'customer',
            'cpf'  => '52998224725',
            'name' => 'Maria Souza',
        ];
    }

    private function contractProvider(): JwtProvider
    {
        return new JwtProvider(self::SECRET, 3600, static fn (): int => self::IAT);
    }

    public function testGeneratesTheExactContractToken(): void
    {
        $this->assertSame(
            self::EXPECTED_TOKEN,
            $this->contractProvider()->generate($this->contractPayload())
        );
    }

    public function testHeaderIsHs256JwtInContractOrder(): void
    {
        $header = explode('.', self::EXPECTED_TOKEN)[0];

        $this->assertSame('{"alg":"HS256","typ":"JWT"}', $this->base64urlDecode($header));
    }

    public function testClaimsMatchTheContract(): void
    {
        $body = explode('.', self::EXPECTED_TOKEN)[1];

        $claims = json_decode($this->base64urlDecode($body), true);
        $this->assertIsArray($claims);

        $this->assertSame(
            ['sub', 'role', 'cpf', 'name', 'iss', 'iat', 'exp'],
            array_keys($claims),
            'A ordem das claims faz parte do contrato com a Lambda.'
        );
        $this->assertSame('oficina-mecanica-api', $claims['iss']);
        $this->assertSame(self::IAT, $claims['iat']);
        $this->assertSame(self::IAT + 3600, $claims['exp']);
    }

    public function testValidatesTheContractTokenWhileItIsFresh(): void
    {
        // Clock pinned inside the validity window so the token is not considered expired.
        $provider = new JwtProvider(self::SECRET, 3600, static fn (): int => self::IAT + 10);

        $claims = $provider->validate(self::EXPECTED_TOKEN);

        $this->assertSame('customer', $claims['role']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $claims['sub']);
    }

    public function testRejectsTheContractTokenSignedWithAnotherSecret(): void
    {
        $provider = new JwtProvider('another-secret', 3600, static fn (): int => self::IAT + 10);

        $this->expectExceptionMessageMatches('/assinatura/i');
        $provider->validate(self::EXPECTED_TOKEN);
    }

    private function base64urlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
