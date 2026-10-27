<?php

namespace Tests\Unit;

use App\Services\FireflyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FireflyServiceTest extends TestCase
{
    public function test_stores_withdrawal_transaction(): void
    {
        config([
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
            'services.firefly.default_source' => 'Cash',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => '1']], 200),
        ]);

        $service = new FireflyService;
        $response = $service->storeTransaction([
            'amount' => 25000,
            'description' => 'Makan siang',
            'type' => 'withdrawal',
        ]);

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8080/api/v1/transactions'
                && $request->hasHeader('Authorization', 'Bearer dummy-firefly-token')
                && $request['transactions'][0]['amount'] === '25000'
                && $request['transactions'][0]['description'] === 'Makan siang'
                && $request['transactions'][0]['source_name'] === 'Cash';
        });
    }

    public function test_stores_transaction_with_specific_date(): void
    {
        config([
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
            'services.firefly.default_source' => 'Cash',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => '1']], 200),
        ]);

        $service = new FireflyService;
        $response = $service->storeTransaction([
            'amount' => 35000,
            'description' => 'Bensin',
            'type' => 'withdrawal',
            'date' => '2026-09-03',
        ]);

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8080/api/v1/transactions'
                && str_contains($request['transactions'][0]['date'], '2026-09-03');
        });
    }

    public function test_stores_deposit_transaction(): void
    {
        config([
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
            'services.firefly.default_source' => 'Cash',
            'services.firefly.default_revenue' => 'Income',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => '1']], 200),
        ]);

        $service = new FireflyService;
        $response = $service->storeTransaction([
            'amount' => 5000000,
            'description' => 'Gaji',
            'type' => 'deposit',
        ]);

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8080/api/v1/transactions'
                && $request['transactions'][0]['type'] === 'deposit'
                && $request['transactions'][0]['destination_name'] === 'Cash'
                && $request['transactions'][0]['source_name'] === 'Income';
        });
    }
}
