<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramService
{
    /**
     * Send a text message to a specific Telegram chat.
     */
    public function sendMessage(string|int $chatId, string $text = 'Pesan dari bot'): Response
    {
        return Http::post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
