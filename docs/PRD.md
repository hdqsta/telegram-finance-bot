# PRD: Telegram Finance Bot + Firefly III

**Disusun oleh:** M. Hatta Siddiq | **Versi:** 1.0 (single-user)

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
   > Transaksi Berhasil Dicatat! (jenis, deskripsi, jumlah, tanggal, dan akun sumber)
5. Jika pesan tidak bisa di-parse (misal nominal tidak ditemukan), bot membalas pesan error yang jelas dan tidak menyimpan transaksi

## 5. Requirement Fungsional

| ID | Requirement | Status |
|----|-------------|--------|
| F1 | Bot dapat menerima pesan teks dari Telegram via webhook | Implemented |
| F2 | Sistem dapat mem-parsing tanggal dari teks (format eksplisit "3 september", atau default ke hari ini jika tidak disebut) | Implemented |
| F3 | Sistem dapat mem-parsing nominal dari teks (mendukung format "35rb", "35ribu", "35000", "35k") | Implemented |
| F4 | Sistem dapat menebak kategori berdasarkan keyword dictionary (bensin→Transport, gorengan/makan→Makanan, dll) | Not implemented (sengaja ditunda; transaksi dicatat tanpa tebakan kategori) |
| F5 | Sistem dapat mengirim transaksi ke Firefly III via API (`POST /api/v1/transactions`) | Implemented |
| F6 | Bot membalas konfirmasi berisi ringkasan transaksi yang tercatat | Implemented |
| F7 | Bot memberi pesan error yang jelas jika parsing gagal total (nominal tidak ditemukan) | Implemented |
| F8 | Kategori yang tidak dikenali di-flag ke user untuk konfirmasi manual, bukan auto-guess | Not implemented (bergantung pada F4) |

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
   ├─ Message Parser Service (regex + keyword map akun)
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

## 9. Perubahan Setelah Implementasi

Fitur berikut ditambahkan di luar PRD awal karena muncul saat pengembangan:

- Command `/undo` untuk menghapus transaksi terakhir (cache 1 jam per chat).
- Pemisahan pemasukan dan pengeluaran: awalan `+` menandai deposit.
- Deteksi akun sumber dari kata kunci (`tf`, `trf`, `transfer`, `qris` memakai akun Mandiri; selain itu akun dompet).
- Keamanan webhook: verifikasi header `X-Telegram-Bot-Api-Secret-Token`, allowlist chat ID, dan deduplikasi `update_id` selama 24 jam.
