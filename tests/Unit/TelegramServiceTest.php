<?php

namespace Tests\Unit;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramServiceTest extends TestCase
{
    public function test_sends_telegram_message(): void
    {
        config(['services.telegram.bot_token' => 'dummy-token']);

        Http::fake([
            'https://api.telegram.org/botdummy-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $telegramService = new TelegramService;
        $response = $telegramService->sendMessage(123456, 'Pesan dari bot');

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botdummy-token/sendMessage'
                && $request['chat_id'] === 123456
                && $request['text'] === 'Pesan dari bot';
        });
    }
}
