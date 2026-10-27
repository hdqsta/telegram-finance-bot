<?php

namespace App\Http\Controllers;

use App\Services\ExpenseParserService;
use App\Services\FireflyService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegramService,
        private ExpenseParserService $parserService,
        private FireflyService $fireflyService
    ) {}

    public function handle(Request $request)
    {
        try {
            $expectedSecret = config('services.telegram.webhook_secret');
            if ($expectedSecret) {
                $receivedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
                if (! $receivedSecret || ! hash_equals((string) $expectedSecret, (string) $receivedSecret)) {
                    Log::warning('Telegram webhook request ignored: invalid secret token');

                    return response()->json(['ok' => true]);
                }
            }

            $chatId = $request->input('message.chat.id');
            $allowedChatId = config('services.telegram.allowed_chat_id');

            if ($allowedChatId && (string) $chatId !== (string) $allowedChatId) {
                Log::warning('Telegram webhook request ignored: unauthorized chat ID');

                return response()->json(['ok' => true]);
            }

            $updateId = $request->input('update_id');
            if ($updateId) {
                $isNew = Cache::add("tg:update:{$updateId}", true, now()->addHours(24));
                if (! $isNew) {
                    Log::info("Telegram update {$updateId} skipped: already processed");

                    return response()->json(['ok' => true]);
                }
            }

            $text = trim((string) $request->input('message.text', ''));

            if (! $chatId || $text === '') {
                return response()->json(['ok' => true]);
            }

            if (strtolower($text) === '/undo') {
                return $this->handleUndo($chatId);
            }

            $parsed = $this->parserService->parse($text);

            if (! $parsed) {
                $this->telegramService->sendMessage(
                    $chatId,
                    "Format tidak dikenali.\n\nContoh penggunaan:\n• Pengeluaran: Makan siang 25000\n• Pemasukan: +5000000 Gaji"
                );

                return response()->json(['ok' => true]);
            }

            try {
                $response = $this->fireflyService->storeTransaction($parsed);

                if ($response->successful()) {
                    $txId = $response->json('data.id');
                    if ($txId) {
                        Cache::put("tg:last_tx:{$chatId}", $txId, now()->addHour());
                    }

                    $formattedAmount = 'Rp '.number_format($parsed['amount'], 0, ',', '.');
                    $typeLabel = $parsed['type'] === 'deposit' ? 'Pemasukan' : 'Pengeluaran';
                    $formattedDate = Carbon::parse($parsed['date'])->translatedFormat('d M Y');
                    $sourceAccount = $parsed['source_account'] ?? 'Dompet';

                    $this->telegramService->sendMessage(
                        $chatId,
                        "Transaksi Berhasil Dicatat!\n\n• Jenis: {$typeLabel}\n• Deskripsi: {$parsed['description']}\n• Jumlah: {$formattedAmount}\n• Tanggal: {$formattedDate}\n• Sumber: {$sourceAccount}"
                    );
                } else {
                    Log::error('Firefly III API Error', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    $this->telegramService->sendMessage(
                        $chatId,
                        "Gagal menyimpan ke Firefly III (HTTP {$response->status()}).\n\nMohon periksa URL Firefly, Token API, atau akun 'Cash' di Firefly III Anda."
                    );
                }
            } catch (\Throwable $e) {
                Log::error('Firefly III Connection Error', ['error' => $e->getMessage()]);

                $this->telegramService->sendMessage(
                    $chatId,
                    "Gagal terhubung ke Firefly III!\n\nTidak dapat menghubungi `".config('services.firefly.url').'`. Pastikan server Firefly III Anda sudah menyala dan URL di `.env` sudah benar.'
                );
            }
        } catch (\Throwable $e) {
            Log::error('Telegram webhook processing error', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    private function handleUndo(string|int $chatId)
    {
        $lastTxId = Cache::get("tg:last_tx:{$chatId}");

        if (! $lastTxId) {
            $this->telegramService->sendMessage(
                $chatId,
                'Tidak ada transaksi terakhir yang dapat di-undo (atau batas waktu 1 jam telah berlalu).'
            );

            return response()->json(['ok' => true]);
        }

        try {
            $response = $this->fireflyService->deleteTransaction($lastTxId);

            if ($response->successful()) {
                Cache::forget("tg:last_tx:{$chatId}");

                $this->telegramService->sendMessage(
                    $chatId,
                    "✅ Transaksi terakhir (ID: {$lastTxId}) berhasil dihapus dari Firefly III."
                );
            } else {
                Log::error('Firefly III Delete API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->telegramService->sendMessage(
                    $chatId,
                    "Gagal menghapus transaksi di Firefly III (HTTP {$response->status()})."
                );
            }
        } catch (\Throwable $e) {
            Log::error('Firefly III Delete Connection Error', ['error' => $e->getMessage()]);

            $this->telegramService->sendMessage(
                $chatId,
                'Gagal terhubung ke Firefly III saat mencoba menghapus transaksi.'
            );
        }

        return response()->json(['ok' => true]);
    }
}
