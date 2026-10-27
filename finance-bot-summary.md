# Telegram Finance Bot — Ringkasan Project

## 1. Tujuan

Membangun bot pencatat pengeluaran pribadi via Telegram. User cukup ketik pengeluaran pakai bahasa natural (contoh: `3 sept beli bensin 35rb`), bot akan mem-parsing teks tersebut menjadi data terstruktur (tanggal, nominal, kategori, deskripsi) lalu otomatis menyimpannya sebagai transaksi ke **Firefly III**.

**Prinsip arsitektur:**
- Firefly III dipakai **as-is / tanpa modifikasi** — hanya sebagai ledger & reporting engine.
- Bot Telegram dibangun **dari nol** menggunakan Laravel, terpisah dari Firefly III, berkomunikasi lewat REST API Firefly.

---

## 2. Tech Stack

| Layer | Teknologi |
|---|---|
| Bahasa & Framework | PHP 8.3, Laravel 13 |
| Ledger backend | Firefly III (self-hosted, unmodified), berjalan di Docker |
| Database Firefly III | Bawaan container Firefly III (terpisah dari Laravel) |
| Database bot Laravel | Belum ditentukan — bot saat ini stateless (tidak menyimpan data sendiri, hanya meneruskan ke Firefly III). Kalau nanti butuh state (misal riwayat chat / kategori custom), baru dipertimbangkan |
| HTTP client | Laravel `Http` facade (Guzzle 8.x bawaan) — tanpa SDK pihak ketiga |
| Bot messaging | Telegram Bot API (REST, dipanggil manual) |
| Environment development | Laragon (Windows), project native (bukan Docker) di `C:\laragon\www\telegram-finance-bot` |
| Environment target production | Docker Compose (rencana, setelah MVP stabil) |
| Testing | PHPUnit (bawaan Laravel), `Http::fake()` untuk mock API eksternal |
| Code style & Tooling | Laravel Pint, `laravel/boost` |

---

## 3. Struktur Folder Project (Laravel)

```
telegram-finance-bot/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── TelegramWebhookController.php   # ✅ terima update dari Telegram & hubungkan alur
│   └── Services/
│       ├── TelegramService.php                 # ✅ kirim pesan ke Telegram API
│       ├── FireflyService.php                  # ✅ kirim transaksi ke Firefly III API
│       └── ExpenseParserService.php             # ✅ parsing teks natural language (nominal, deskripsi, tipe)
├── config/
│   └── services.php                            # ✅ berisi config telegram.bot_token & firefly
├── routes/
│   └── api.php                                 # ✅ route POST /api/telegram/webhook
├── tests/
│   └── Unit/
│       ├── TelegramServiceTest.php             # ✅ test sendMessage()
│       ├── FireflyServiceTest.php              # ✅ test storeTransaction()
│       └── ExpenseParserServiceTest.php        # ✅ test parse() dengan berbagai kasus
└── .env                                        # TELEGRAM_BOT_TOKEN, FIREFLY_III_URL, FIREFLY_III_TOKEN
```

---

## 4. Definisi MVP

Scope MVP (versi paling minimal yang sudah bisa dipakai harian) adalah:

1. User kirim pesan teks ke bot Telegram dengan format bebas tapi konsisten, contoh: `beli bensin 35rb` atau `+5000000 Gaji`
2. Bot berhasil parsing teks jadi komponen wajib:
   - **Nominal** (angka, termasuk suffix umum seperti `rb`/`ribu`, `k`, `Rp`)
   - **Deskripsi** (sisa teks setelah nominal diekstrak)
   - **Tipe Transaksi** (`withdrawal` / pengeluaran default, `deposit` / pemasukan jika ada tanda `+`)
3. Bot kirim data hasil parsing tadi ke Firefly III lewat REST API sebagai satu transaksi baru
4. Bot balas ke user konfirmasi transaksi berhasil disimpan (atau pesan error kalau gagal parsing/API gagal)

**Di luar scope MVP** (boleh menyusul setelah versi dasar jalan):
- Kategori otomatis (deteksi kategori dari teks, misal "bensin" → kategori "Transportasi")
- Edit/hapus transaksi lewat chat
- Laporan/ringkasan lewat chat (misal `/summary bulan ini`)
- Multi-user / multi-akun Firefly III
- Voice message / OCR struk

---

## 5. Arsitektur & Alur Data

```
User (Telegram)
      │  ketik pesan teks
      ▼
Telegram Bot API
      │  webhook POST
      ▼
Laravel App (native, jalan di localhost)
      │
      ├─ TelegramWebhookController  → terima update dari Telegram
      ├─ ExpenseParserService       → parsing teks → data terstruktur (sudah dibuat)
      ├─ FireflyService             → kirim transaksi ke Firefly III API (sudah dibuat)
      └─ TelegramService            → kirim balasan konfirmasi ke user (sudah dibuat)
      │
      ▼
Firefly III (Docker, self-hosted)
      │  REST API (Personal Access Token)
      ▼
Database transaksi tersimpan
```

---

## 6. Keputusan Teknis

| Aspek | Keputusan | Alasan |
|---|---|---|
| Ledger engine | Firefly III, self-hosted via Docker | Aplikasi kompleks (DB, cron, queue) — docker compose menyederhanakan setup, tidak perlu diedit |
| Bot | Laravel, dibangun dari nol | Perlu logic custom untuk parsing bahasa natural, fleksibel dikembangkan |
| Environment bot (development) | **Native di laptop** (`php artisan serve`), bukan Docker | Iterasi lebih cepat saat development — tidak perlu rebuild image tiap ubah code |
| Environment bot (nanti, production) | Rencana masuk ke Docker Compose bareng Firefly III | Biar jalan permanen tanpa laptop harus nyala terus |
| Komunikasi ke Telegram API | Laravel `Http` facade langsung (tanpa SDK pihak ketiga) | Package `irazasyed/telegram-bot-sdk` konflik versi dengan Guzzle 8.x bawaan Laravel 13 — API Telegram cukup simpel untuk dipanggil manual |
| Koneksi Firefly III ↔ Bot (saat dev) | `http://localhost` (port 80, karena container `8080` di-mapping ke host port `80`) | Dicek langsung dari `docker inspect` |

---

## 7. Progress Sejauh Ini

- [x] Firefly III jalan di Docker, port ter-expose di `localhost:80`
- [x] Project Laravel baru (`telegram-finance-bot`) dibuat via Composer, native di Laragon
- [x] `config/services.php` — ditambah config `telegram.bot_token` dan `firefly`
- [x] `.env` / `.env.example` — ditambah key `TELEGRAM_BOT_TOKEN`, `FIREFLY_III_URL`, `FIREFLY_III_TOKEN`
- [x] `app/Services/TelegramService.php` — service `sendMessage()` untuk kirim pesan ke Telegram API
- [x] `app/Services/ExpenseParserService.php` — logic parsing nominal (`25k`, `25rb`, `25ribu`, `Rp`), deskripsi, dan tipe (`withdrawal`/`deposit`)
- [x] `app/Services/FireflyService.php` — service pengirim payload transaksi ke REST API Firefly III
- [x] `app/Http/Controllers/TelegramWebhookController.php` — controller `handle()` yang mengintegrasikan Parser + Firefly + Telegram Service
- [x] `tests/Unit/` — 3 unit test (`TelegramServiceTest`, `ExpenseParserServiceTest`, `FireflyServiceTest`) lulus 100% (9 tests, 23 assertions)
- [x] Kode diformat dengan Laravel Pint & dikonfigurasi dengan `laravel/boost`
- [x] Route `POST /api/telegram/webhook` didaftarkan di `routes/api.php`
- [x] Tunnel publik (ngrok) HTTPS & webhook Telegram sudah di-setup

---

## 8. Yang Belum Dikerjakan

1. **Pengujian End-to-End Live** — Uji coba kirim pesan dari Telegram asli ke server Firefly III yang sedang menyala di Docker.
2. **Kategori Otomatis (Dictionary Mapping)** — Pemetaan kata kunci pesan ke kategori di Firefly III (misal "bensin" → Transport).
3. **Migrasi ke Docker Compose** (setelah MVP stabil) — Menggabungkan bot Laravel dan Firefly III dalam satu setup Docker Compose.

---

## 9. Environment Variables yang Dibutuhkan

```env
TELEGRAM_BOT_TOKEN=       # dari BotFather (/newbot)
FIREFLY_III_URL=http://localhost
FIREFLY_III_TOKEN=        # Personal Access Token dari Firefly III
FIREFLY_III_DEFAULT_SOURCE=Cash
```

---

## 10. Catatan Penting

- Token Telegram (`TELEGRAM_BOT_TOKEN`) dan token Firefly III (`FIREFLY_III_TOKEN`) adalah dua hal yang **sepenuhnya terpisah**.
- Hindari pemanggilan `env()` langsung di luar `config/*.php` agar tidak bermasalah saat `php artisan config:cache`. Service selalu menggunakan `config('services...')`.
- Menggunakan `Http::fake()` dalam unit test memastikan testing berjalan cepat tanpa harus bergantung pada server live atau Telegram API.
