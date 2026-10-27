# Roadmap MVP — Telegram Finance Bot

**Prinsip:** kerjakan 6 hal di bawah, lalu **berhenti dan pakai bot ini harian minimal 2 minggu**.
Semua ide lain (kategori otomatis, OCR struk, fallback LLM, multi-user, alert budget) masuk
[Parkir](#parkir--jangan-dikerjakan-dulu) dan tidak disentuh sebelum periode pemakaian selesai.

**Alasan:** tujuan awal project ini nyatet pengeluaran, bukan punya codebase yang rapi.
Selama bot belum dipakai harian, semua fitur tambahan cuma tebakan.

---

## Urutan

| # | Tahap | Kenapa sekarang | Status |
|---|---|---|---|
| 1 | Keamanan webhook | Endpoint publik nulis ke ledger keuangan | ☑ |
| 2 | Dedupe & tahan retry | Transaksi dobel = data kotor sejak hari pertama | ☑ |
| 3 | Parsing tanggal | Nyatet telat itu kebiasaan normal | ☑ |
| 4 | E2E live test | Titik di mana bot benar-benar terbukti jalan | ☑ |
| 5 | Undo | Salah ketik pasti terjadi; tanpa undo, males percaya datanya | ☑ |
| 6 | Deploy + backup | Nentuin bot kepake harian atau nggak | ☐ |

---

## 1. Keamanan webhook

Saat ini webhook publik lewat ngrok tanpa autentikasi apa pun.

- [x] Set `secret_token` waktu panggil `setWebhook`, simpan di `.env` sebagai `TELEGRAM_WEBHOOK_SECRET`
- [x] Di `TelegramWebhookController`, cek header `X-Telegram-Bot-Api-Secret-Token`; kalau nggak cocok → balas 200 kosong, jangan proses (jangan 403, biar nggak ngasih sinyal)
- [x] Whitelist `chat_id` sendiri via `TELEGRAM_ALLOWED_CHAT_ID`; chat lain diabaikan diam-diam
- [x] Pastikan token dan secret nggak pernah ke-log

**Selesai kalau:** request ke URL webhook tanpa header secret yang benar tidak menghasilkan transaksi di Firefly.

---

## 2. Dedupe & tahan retry

Telegram kirim ulang update kalau webhook lambat atau balas non-200.

- [x] Balas `200` secepat mungkin; bungkus seluruh alur proses dalam `try/catch` supaya exception tidak jadi non-200
- [x] Dedupe pakai `update_id` → `Cache::add("tg:update:{$updateId}", true, ttl)`. Kalau `add()` mengembalikan false, update sudah pernah diproses → skip. TTL 24 jam cukup
- [x] Error dibalas ke user sebagai pesan biasa, bukan sebagai HTTP error
- [x] **Feature test** untuk controller (belum ada sama sekali; sekarang cuma unit test service):
  - [x] update valid → transaksi dikirim ke Firefly (`Http::fake()`), balasan konfirmasi terkirim
  - [x] `update_id` sama dikirim dua kali → Firefly cuma kepanggil sekali
  - [x] secret salah → nggak ada panggilan ke Firefly
  - [x] teks nggak bisa diparsing → balasan error, status tetap 200
  - [x] Firefly balas 500 → status tetap 200, user dapat pesan gagal

**Catatan:** ini titik di mana keputusan "stateless" mulai kerasa. Cache sudah cukup, belum perlu tabel database.

---

## 3. Parsing tanggal

Ada mismatch di ringkasan: bagian Tujuan nyebut contoh `3 sept beli bensin 35rb`, tapi MVP dan parser cuma nominal + deskripsi + tipe.

- [x] Tambah ekstraksi tanggal di `ExpenseParserService`, default hari ini kalau nggak ketemu
- [x] Format yang didukung minimal:
  - [x] `kemarin`, `hari ini`, `kemarin lusa`
  - [x] `3 sept`, `3 september`, `3/9`, `03-09`
  - [x] tahun opsional; kalau tanggal jatuh di masa depan, mundurin ke tahun sebelumnya
- [x] Tanggal yang terpakai harus dibuang dari deskripsi
- [x] Kirim ke Firefly dalam format ISO 8601 (`YYYY-MM-DD`)
- [x] Tampilkan tanggal di balasan konfirmasi, supaya salah baca langsung kelihatan
- [x] Unit test tambahan untuk tiap format di atas

---

## 4. E2E live test

Kirim pesan dari Telegram asli ke Firefly III yang nyala di Docker. Sambil jalan, cek tiga hal ini:

- [x] **Deposit.** `FIREFLY_III_DEFAULT_SOURCE=Cash` cocok untuk withdrawal. Untuk deposit arahnya kebalik: source = revenue account (`Income`), destination = asset account (`Cash`). Cek payload `FireflyService` — ini bug klasik
- [x] **Angka ambigu.** `beli 2 galon 40rb` kebaca 40rb. Aturan: prioritaskan angka bersuffix (`rb`/`ribu`/`k`/`jt`/`Rp`), kalau semua polos ambil angka terakhir
- [x] **Format nominal:** `1,5jt`, `1.5jt`, `35.000`, `35rb`, `35000`, `Rp35.000`
- [x] **Header ke Firefly:** `Accept: application/json` (+ `Content-Type: application/json`)
- [x] Verifikasi transaksi benar-benar muncul di UI Firefly dengan nominal, tanggal, dan arah akun yang tepat

Skenario minimal yang harus lolos: pengeluaran hari ini, pengeluaran tanggal lampau, pemasukan (`+5000000 Gaji`), teks ngawur, dan Firefly lagi mati.

---

## 5. Undo

- [x] Simpan `transaction_id` terakhir per chat di Cache (TTL 1 jam)
- [x] Command `/undo` → `DELETE /api/v1/transactions/{id}` ke Firefly
- [x] Balasan jelas kalau nggak ada yang bisa di-undo

---

## 6. Deploy + backup

Sekarang bot cuma hidup kalau laptop nyala dan ngrok jalan — dan URL ngrok gratis berubah tiap restart, jadi webhook harus di-set ulang terus.

- [ ] Pindah bot ke Docker Compose bareng Firefly III
- [ ] Jalankan di VPS kecil atau mini server di rumah
- [ ] Domain/subdomain + HTTPS, set webhook sekali, selesai
- [ ] Alternatif buat dev: long polling, nggak butuh ngrok sama sekali
- [ ] **Backup database Firefly III** — cron dump harian ke lokasi lain. Ini data keuangan, dan paling sering kelewat
- [ ] Alert error ke chat Telegram sendiri, biar tahu kalau bot mati

**Selesai kalau:** bisa nyatet pengeluaran dari HP tanpa nyalain laptop.

---

## Berhenti di sini

Setelah tahap 6, **pakai dulu minimal 2 minggu**. Selama itu, satu-satunya pekerjaan yang boleh dilakukan adalah memperbaiki hal yang bikin pencatatan harian gagal.

Catat di file terpisah tiap kali terjadi:
- teks yang salah diparsing (tulis teks aslinya persis)
- momen kepengen fitur yang belum ada, dan konteksnya apa

Setelah 2 minggu, catatan itu yang nentuin urutan berikutnya — bukan daftar di bawah.

---

## Parkir — jangan dikerjakan dulu

- **Pilih akun** (`makan 25rb @gopay`) — kemungkinan besar ini yang paling cepat kerasa butuh, tapi tunggu buktinya dari pemakaian
- **Command baca** (`/hariini`, `/bulanini`, `/saldo`) via GET API Firefly — murah dibikin, bikin bot kerasa berguna bukan cuma alat input
- **Kategori otomatis** — pertimbangkan pakai **rules engine Firefly III** (nggak perlu kode, sejalan dengan prinsip "Firefly as-is"). Trade-off: balasan bot nggak bisa nampilin kategori kecuali fetch ulang. Item "Kategori Otomatis" di daftar lama layak dipikir ulang, mungkin nggak perlu dibangun di bot sama sekali
- **Fallback LLM** untuk parsing — hanya kalau regex gagal, jangan jadi jalur utama (biaya, latency, dan kadang salah baca nominal). Untuk keuangan, parser deterministik lebih bisa dipercaya
- **Voice note & OCR struk**
- **Alert budget** ("bulan ini makan udah 80% budget")
- **Multi-user** — maksa pikir ulang soal auth dan state; jangan dikerjain sebelum ada kebutuhan nyata

---

## Env variables (update)

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=      # baru — tahap 1
TELEGRAM_ALLOWED_CHAT_ID=     # baru — tahap 1
FIREFLY_III_URL=http://localhost
FIREFLY_III_TOKEN=
FIREFLY_III_DEFAULT_SOURCE=Cash
```
