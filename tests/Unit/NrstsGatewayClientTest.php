<?php

namespace Tests\Unit;

use App\ClientExamples\NrstsGatewayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NrstsGatewayClientTest extends TestCase
{
    public function test_client_reuses_token_only_while_outside_expiry_buffer(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        Http::fakeSequence()
            ->push([
                'access_token' => 'first-token',
                'token_type' => 'Bearer',
                'expires_in' => 120,
            ])
            ->push([
                'access_token' => 'second-token',
                'token_type' => 'Bearer',
                'expires_in' => 120,
            ]);
        $client = new NrstsGatewayClient(
            app(Factory::class),
            app('cache.store'),
            'https://gateway.example',
            'api-directory-id',
            'bcaigw-client-id',
            'one-time-secret',
            cacheBufferSeconds: 60,
        );

        $this->assertSame('first-token', $client->accessToken());
        $this->assertSame('first-token', $client->accessToken());
        Http::assertSentCount(1);

        Carbon::setTestNow('2026-09-01 12:01:01');
        $this->assertSame('second-token', $client->accessToken());
        Http::assertSentCount(2);
    }
}
