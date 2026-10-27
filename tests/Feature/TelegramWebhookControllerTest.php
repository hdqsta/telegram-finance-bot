<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookControllerTest extends TestCase
{
    public function test_ignores_request_with_invalid_secret_token(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
        ]);

        Http::fake();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 123456789],
                'text' => 'Makan siang 25000',
            ],
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertNothingSent();
    }

    public function test_ignores_request_from_unauthorized_chat_id(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
        ]);

        Http::fake();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ])->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 999999999], // unauthorized chat
                'text' => 'Makan siang 25000',
            ],
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertNothingSent();
    }

    public function test_processes_valid_request_with_valid_secret_and_chat_id(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => 1]], 200),
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ])->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 123456789],
                'text' => 'Makan siang 25000',
            ],
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8080/api/v1/transactions';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage';
        });
    }

    public function test_deduplicates_duplicate_update_id(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => 1]], 200),
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'update_id' => 100,
            'message' => [
                'chat' => ['id' => 123456789],
                'text' => 'Makan siang 25000',
            ],
        ];

        // First request - processed
        $res1 = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', $payload);
        $res1->assertStatus(200)->assertJson(['ok' => true]);

        // Second request with SAME update_id - skipped
        $res2 = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', $payload);
        $res2->assertStatus(200)->assertJson(['ok' => true]);

        // Firefly should only be called ONCE
        Http::assertSentCount(2); // 1 Firefly call + 1 Telegram reply
    }

    public function test_handles_unparseable_text_returns_200_and_error_message(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
        ]);

        Http::fake([
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 200,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => 'halo bot', // invalid text (no amount)
                ],
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage'
                && str_contains($request['text'], 'Format tidak dikenali');
        });
    }

    public function test_handles_firefly_500_error_returns_200_and_failure_message(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['error' => 'Internal Server Error'], 500),
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 300,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => 'Beli bensin 35000',
                ],
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage'
                && str_contains($request['text'], 'Gagal menyimpan ke Firefly III');
        });
    }

    public function test_undo_command_deletes_cached_transaction(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => '42']], 200),
            'http://localhost:8080/api/v1/transactions/42' => Http::response(null, 204),
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        // 1. Create transaction first
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 500,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => 'Bensin 35rb',
                ],
            ]);

        // 2. Send /undo command
        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 501,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => '/undo',
                ],
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8080/api/v1/transactions/42'
                && $request->method() === 'DELETE';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage'
                && str_contains($request['text'], 'berhasil dihapus');
        });
    }

    public function test_undo_command_without_cached_transaction_replies_error(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
        ]);

        Http::fake([
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 600,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => '/undo',
                ],
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage'
                && str_contains($request['text'], 'Tidak ada transaksi terakhir');
        });
    }

    public function test_webhook_displays_source_account_in_confirmation_message(): void
    {
        config([
            'services.telegram.bot_token' => 'dummy-bot-token',
            'services.telegram.webhook_secret' => 'my-secret-token',
            'services.telegram.allowed_chat_id' => '123456789',
            'services.firefly.url' => 'http://localhost:8080',
            'services.firefly.token' => 'dummy-firefly-token',
        ]);

        Http::fake([
            'http://localhost:8080/api/v1/transactions' => Http::response(['data' => ['id' => 1]], 200),
            'https://api.telegram.org/botdummy-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token'])
            ->postJson('/api/telegram/webhook', [
                'update_id' => 700,
                'message' => [
                    'chat' => ['id' => 123456789],
                    'text' => 'tf beli sate 18rb',
                ],
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-bot-token/sendMessage'
                && str_contains($request['text'], '• Sumber: Mandiri');
        });
    }
}
