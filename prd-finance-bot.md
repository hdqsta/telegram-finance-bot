# PRD: Telegram Finance Bot + Firefly III

## 1. Latar Belakang

Pencatatan pengeluaran harian sering kali terlewat karena proses input yang ribet (buka app, isi form, pilih kategori manual). Solusi: cukup ketik pesan natural language ke Telegram, transaksi otomatis tercatat di sistem pembukuan.

## 2. Tujuan

- Mempermudah pencatatan transaksi harian tanpa buka aplikasi terpisah
- Parsing otomatis dari teks natural language (tanggal, nominal, kategori, deskripsi)
- Memanfaatkan Firefly III sebagai backend ledger & reporting yang sudah teruji, tanpa modifikasi
- Membangun komponen bot dari nol sebagai portofolio teknis (Laravel + integrasi API + NLP parsing)

## 3. Non-Goals (di luar scope awal)

- Tidak membangun ulang sistem pembukuan/reporting (dipakai dari Firefly III apa adanya)
- Tidak multi-user di awal — fokus single-user (personal use)
- Tidak menangani multi-currency di versi awal
- Tidak ada fitur budget/bill reminder di versi awal (Firefly III sudah punya, cukup dipakai manual dari dashboard-nya kalau butuh)

## 4. User Flow Utama

1. User ketik pesan ke bot Telegram, contoh:
   - "3 september beli bensin 35rb"
   - "gorengan 10rb"
   - "makan siang hari ini 25000"
2. Bot parsing teks → hasilkan: tanggal, nominal, kategori (tebakan), deskripsi
3. Bot kirim data ke Firefly III via REST API → transaksi tersimpan
4. Bot balas konfirmasi ke user, contoh:
   > ✅ Tercatat: 3 Sep — Bensin — Rp35.000 (Transport)
5. Jika parsing gagal/ambigu, bot minta klarifikasi (misal kategori tidak dikenali) alih-alih asal tebak

## 5. Requirement Fungsional

| ID | Requirement |
|----|-------------|
| F1 | Bot dapat menerima pesan teks dari Telegram via webhook |
| F2 | Sistem dapat mem-parsing tanggal dari teks (format eksplisit "3 september", atau default ke hari ini jika tidak disebut) |
| F3 | Sistem dapat mem-parsing nominal dari teks (mendukung format "35rb", "35ribu", "35000", "35k") |
| F4 | Sistem dapat menebak kategori berdasarkan keyword dictionary (bensin→Transport, gorengan/makan→Makanan, dll) |
| F5 | Sistem dapat mengirim transaksi ke Firefly III via API (`POST /api/v1/transactions`) |
| F6 | Bot membalas konfirmasi berisi ringkasan transaksi yang tercatat |
| F7 | Bot memberi pesan error yang jelas jika parsing gagal total (nominal tidak ditemukan) |
| F8 | Kategori yang tidak dikenali di-flag ke user untuk konfirmasi manual, bukan auto-guess |

## 6. Requirement Non-Fungsional

- Response time bot < 3 detik untuk kasus normal (regex/keyword parsing)
- Sistem harus tetap berjalan lokal (self-hosted), tidak wajib bergantung ke layanan berbayar
- Kredensial API (token Firefly III, token Telegram bot) disimpan di `.env`, tidak hardcoded

## 7. Arsitektur Singkat

```
Telegram User
   │ pesan teks
   ▼
Telegram Bot API
   │ webhook (POST)
   ▼
Laravel App (bot-finance-tracker)
   ├─ Webhook Controller (terima update Telegram)
   ├─ Message Parser Service (regex + keyword dictionary)
   ├─ Firefly III API Client (kirim transaksi)
   └─ Response Formatter (balas ke user via Telegram API)
   │
   ▼
Firefly III (self-hosted, via Docker)
   └─ Database transaksi, kategori, laporan
```

## 8. Metrik Keberhasilan (versi personal, informal)

- Parsing berhasil dengan benar untuk >90% pesan dengan format yang biasa dipakai sendiri
- Tidak perlu buka Firefly III dashboard untuk sekadar input transaksi harian
- Waktu input transaksi turun signifikan dibanding cara manual sebelumnya

---

# Task Breakdown

## Epic 1: Setup Firefly III

- [ ] 1.1 Install Firefly III via Docker (docker-compose resmi)
- [ ] 1.2 Setup akun awal, currency default IDR
- [ ] 1.3 Buat kategori dasar (Makanan, Transport, Tagihan, Hiburan, Lainnya)
- [ ] 1.4 Buat akun sumber dana (misal "Dompet", "Rekening Bank")
- [ ] 1.5 Generate Personal Access Token dari Settings → API Tokens
- [ ] 1.6 Test API manual pakai Postman/curl — buat 1 transaksi via `POST /api/v1/transactions`
- [ ] 1.7 Dokumentasikan struktur payload yang valid (source_id, destination, category, amount, date, description)

## Epic 2: Setup Project Bot (Laravel)

- [ ] 2.1 Init project Laravel baru (terpisah dari Firefly III)
- [ ] 2.2 Buat bot Telegram via @BotFather, dapatkan token
- [ ] 2.3 Install library Telegram bot SDK untuk Laravel
- [ ] 2.4 Setup route + controller untuk menerima webhook Telegram
- [ ] 2.5 Setup webhook URL (perlu HTTPS — bisa pakai ngrok untuk development lokal)
- [ ] 2.6 Test: kirim pesan ke bot, pastikan payload masuk ke Laravel log

## Epic 3: Message Parser Service

- [ ] 3.1 Buat service class `TransactionMessageParser`
- [ ] 3.2 Implementasi ekstraksi nominal (regex untuk pola "35rb", "35ribu", "35k", "35000")
- [ ] 3.3 Implementasi ekstraksi tanggal (parsing "3 september", default ke hari ini jika tidak ada)
- [ ] 3.4 Implementasi keyword dictionary untuk kategori (mapping kata kunci → kategori)
- [ ] 3.5 Implementasi ekstraksi deskripsi (sisa teks setelah nominal & tanggal dibuang)
- [ ] 3.6 Unit test parser dengan berbagai variasi contoh pesan
- [ ] 3.7 Handle kasus gagal parsing (nominal tidak ketemu → return error terstruktur)
- [ ] 3.8 Handle kasus kategori tidak dikenali (flag untuk klarifikasi, bukan auto-guess)

## Epic 4: Integrasi Firefly III API

- [ ] 4.1 Buat service class `FireflyApiClient`
- [ ] 4.2 Implementasi method `createTransaction()` — mapping hasil parser ke payload API Firefly III
- [ ] 4.3 Setup HTTP client Laravel dengan auth token dari `.env`
- [ ] 4.4 Handle response sukses — ambil data transaksi yang baru dibuat untuk konfirmasi
- [ ] 4.5 Handle response error dari API (kategori/akun tidak ditemukan, dll)

## Epic 5: Response Formatter & Bot Reply

- [ ] 5.1 Buat format pesan konfirmasi sukses (tanggal, nominal, kategori, deskripsi)
- [ ] 5.2 Buat format pesan error/klarifikasi (kategori tidak dikenali, nominal tidak ditemukan)
- [ ] 5.3 Kirim balasan ke user via Telegram Bot API

## Epic 6: Testing & Refinement

- [ ] 6.1 Test end-to-end dengan berbagai variasi pesan asli (bukan cuma contoh di PRD)
- [ ] 6.2 Catat pola pesan yang gagal parsing, tambahkan ke regex/dictionary
- [ ] 6.3 (Opsional) Evaluasi kebutuhan upgrade ke LLM-based parsing jika rule-based sering meleset
- [ ] 6.4 Deploy lokal untuk pemakaian harian (jalankan via supervisor/systemd biar bot selalu aktif)

## Epic 7 (Opsional, lanjutan): Peningkatan

- [ ] 7.1 Command khusus untuk cek ringkasan bulanan langsung dari Telegram
- [ ] 7.2 Command untuk edit/hapus transaksi terakhir jika salah input
- [ ] 7.3 Eksplor LLM-based parsing untuk menangani variasi bahasa yang lebih luas
