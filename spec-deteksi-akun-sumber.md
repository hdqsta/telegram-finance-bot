# Spec: Deteksi Akun Sumber Dana (Source Account)

## Konteks

Saat ini semua transaksi dicatat pakai satu source account default (`FIREFLY_III_DEFAULT_SOURCE=Cash`/Dompet), padahal kenyataannya sumber dana beda-beda tergantung metode bayar:

- `tf beli sate 18rb` → harusnya kepotong dari Rekening Bank, bukan Dompet
- `qris main billiard 2 jam 200rb` → juga dari Rekening Bank (saat ini semua QRIS dari satu rekening yang sama)
- `60rb beli kertas` → nggak ada indikasi metode bayar → tetap default Dompet, ini sudah benar

**Prinsip desain:** ikuti pola yang sudah dipakai untuk kategori (keyword dictionary + deterministik, bukan LLM, bukan nanya balik ke user). Bot tetap stateless — kalau salah tebak, user lihat dari balasan konfirmasi dan benerin lewat `/undo` + ketik ulang pakai keyword eksplisit.

## Scope

- Tambah dictionary keyword untuk source account, paralel dengan dictionary kategori yang sudah ada.
- Default (tidak ada keyword cocok): **Dompet**.
- Keyword awal:
  - `tf`, `transfer`, `trf` → **Mandiri**
- `qris` → **Mandiri** (sementara — saat ini semua QRIS memang satu sumber; lihat catatan di bawah)
- Default (tidak ada keyword cocok): **dompet**.

## Out of scope — jangan dikerjain sekarang

- Deteksi wallet spesifik (GoPay, DANA, OVO, dll). `qris` sengaja di-map statis ke satu akun karena saat ini memang cuma satu sumber. Baru relevan kalau e-wallet beneran kepecah ke beberapa akun — dan solusinya nanti bukan mempercanggih deteksi `qris`, tapi user mulai sebut nama wallet eksplisit di pesan (`gopay ...`, `dana ...`), lalu dictionary tinggal ditambah entry per nama wallet.
- Flow conversational ("bot nanya akun mana yang dipakai"). Ini butuh state (nyimpen transaksi pending, nunggu balesan, matching balesan ke transaksi yang mana) — kontradiksi sama keputusan desain stateless/deterministic yang sudah ada.

## Task Breakdown

- [x] Tambah `sourceAccountKeywords` dictionary di `ExpenseParserService`, format sama seperti dictionary kategori yang sudah ada
- [x] Tambah langkah ekstraksi source account di dalam `parse()`; hasil masuk ke array balikan sebagai key `source_account` (default `"dompet"` kalau tidak ada keyword yang cocok)
- [x] Update `FireflyService::storeTransaction()` supaya pakai `$parsed['source_account']` sebagai source, bukan hardcode dari env `FIREFLY_III_DEFAULT_SOURCE`
- [x] Update format balasan konfirmasi di `TelegramWebhookController` (`storeTransaction` success path) untuk menambahkan baris akun, contoh:
  ```
  Transaksi Berhasil Dicatat!

  • Jenis: Pengeluaran
  • Deskripsi: Sate
  • Jumlah: Rp 18.000
  • Tanggal: 27 Sep 2026
  • Sumber: Mandiri
  ```
- [x] Unit test `ExpenseParserService`: tiap keyword (`tf`, `transfer`, `trf`, `qris`) menghasilkan `source_account` yang benar (`Mandiri`); teks tanpa keyword apa pun menghasilkan default `dompet`
- [x] Feature test `TelegramWebhookController`: pesan dengan keyword `tf`/`qris` menghasilkan balasan konfirmasi yang menyebut nama akun yang benar

## Contoh Kasus Uji

| Input | `source_account` terdeteksi |
|---|---|
| `tf beli sate 18rb` | Mandiri |
| `qris main billiard 2 jam 200rb` | Mandiri |
| `60rb beli kertas` | dompet (default) |
| `trf bayar listrik 150rb` | Mandiri |
| `gorengan 10rb` | dompet (default) |

## Selesai Kalau

- Semua kasus uji di atas lolos test otomatis
- Balasan konfirmasi Telegram selalu menampilkan akun sumber yang dipakai
- Tidak ada state baru ditambahkan (Cache/DB) khusus untuk fitur ini — tetap stateless

## Catatan untuk nanti (jangan dikerjain sekarang)

Begitu e-wallet beneran kepecah ke beberapa akun terpisah, keyword `qris` sendirian tidak akan cukup untuk disambiguasi — informasi metode bayar tidak menyimpan identitas wallet. Solusinya nanti: user mengetik nama wallet eksplisit di pesan, dan dictionary ditambah entry per nama wallet. Dicatat di sini supaya alasan kenapa `qris` di-map statis untuk sekarang tidak hilang konteksnya.
