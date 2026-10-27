# Ringkasan Implementasi Telegram Service

Dokumen ini memuat ringkasan dari langkah-langkah dan perubahan file yang telah dilakukan dalam pembuatan komponen pengiriman pesan Telegram pada aplikasi Laravel.

---

## 1. Setup & Inisialisasi Dependensi

- **Verifikasi Lingkungan**: Memastikan PHP 8.3+ dan Composer 2.7+ sudah terinstall.
- **Instalasi Laravel Boost**:
  - Menginstall package `laravel/boost` (`composer require laravel/boost --dev`).
  - Menjalankan `php artisan boost:install` untuk memasang pedoman pengembangan dan skill Laravel Boost.

---

## 2. Perubahan Konfigurasi & Environment

### `config/services.php`
Menambahkan konfigurasi service Telegram agar `env('TELEGRAM_BOT_TOKEN')` dapat diakses secara aman dan mendukung konfig caching:

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
],
```

### `.env` & `.env.example`
Menambahkan key environment untuk menyimpan Token Telegram Bot:

```env
TELEGRAM_BOT_TOKEN=
```

---

## 3. pembuatan Service Class

### `app/Services/TelegramService.php`
Membuat class service reusable untuk menangani pengiriman pesan via Telegram Bot API:

```php
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
```

---

## 4. Pengujian Otomatis (Unit Testing)

### `tests/Unit/TelegramServiceTest.php`
Membuat unit test untuk menguji fungsi `TelegramService::sendMessage()` menggunakan `Http::fake()` tanpa melakukan HTTP request nyata ke Telegram API:

```php
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
```

---

## 5. Formatter & Verifikasi

- **Laravel Pint**: Kode PHP diformat sesuai standar Laravel (`vendor/bin/pint`).
- **PHPUnit / Artisan Test**: Seluruh test dijalankan (`php artisan test --compact`) dan lulus (100% Passed).

---

## Cara Penggunaan

Service dapat dipanggil di Controller, Command, atau Job seperti berikut:

```php
use App\Services\TelegramService;

// Memanggil via Dependency Injection / helper app()
$telegramService = app(TelegramService::class);
$telegramService->sendMessage($chatId, 'Pesan dari bot');
```
