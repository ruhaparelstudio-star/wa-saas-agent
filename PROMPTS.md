# PROMPTS.md — Prompt Library & Version History
# Semua prompt final tersimpan di sini dengan versioning.
# JANGAN ubah prompt tanpa jalankan accuracy test dulu.
# Jika accuracy turun > 5%: ROLLBACK ke versi sebelumnya.

---

## ATURAN PROMPT VERSIONING

```
Format  : v{major}.{minor}
Major   : perubahan struktur fundamental
Minor   : penyesuaian minor

Sebelum ubah prompt:
1. Catat versi dan accuracy saat ini di Accuracy History
2. Buat versi baru
3. Jalankan test suite 3x, ambil rata-rata
4. Jika accuracy turun > 5%: rollback
5. Update PROGRESS.md dengan hasil

Test suite maksimal 3x per hari (OpenAI cost management).
```

---

## PROMPT 1 — INTENT CLASSIFIER

### Versi Aktif: v0.0 (diisi setelah POC Phase 0)

### Accuracy History
```
v0.0 | Tanggal: - | Run1: -% | Run2: -% | Run3: -% | Avg: -%
```

### Template Final
```
[DIISI SETELAH ITERASI POC PHASE 0]
[Paste exact prompt yang menghasilkan accuracy tertinggi]
```

### Catatan Iterasi
```
[DIISI SAAT POC]
Apa yang berhasil:
Apa yang tidak berhasil:
Edge case yang ditemukan:
```

### Karakter Khusus Bahasa Indonesia yang Perlu Diperhatikan
```
Singkatan umum yang harus ter-handle:
- "kak" = sapaan, bukan nama
- "dong", "nih", "sih", "ya", "deh" = filler words
- "tgl" = tanggal
- "jt", "juta" = juta rupiah
- "rb", "ribu" = ribu rupiah
- "utk" = untuk
- "yg" = yang
- "bs" = bisa
- "ga", "gak", "ngga" = tidak
- "udah", "udah" = sudah
- "blm", "belom" = belum
- "mau", "mo" = mau/ingin
- "gimana", "gmn" = bagaimana

Intent yang sering ambigu (perlu few-shot lebih banyak):
- ask_price vs ask_package_detail (beda: harga vs isi paket)
- intro_interest vs ask_package_list (beda: baru kenal vs langsung tanya list)
- provide_preference vs provide_date (beda: prefer vs confirm tanggal)
- correction vs provide_date (beda: koreksi vs pertama kali kasih info)
```

---

## PROMPT 2 — ENTITY EXTRACTOR

### Versi Aktif: v0.0 (diisi setelah POC Phase 0)

### Accuracy History
```
v0.0 | Tanggal: - | Run1: -% | Run2: -% | Run3: -% | Avg: -%
```

### Template Final
```
[DIISI SETELAH ITERASI POC PHASE 0]
```

### Normalisasi Rules yang WAJIB Ada di Prompt
```
Tanggal:
- "5 feb" → 2025-02-05 (asumsikan tahun mendatang)
- "5/2" → 2025-02-05 (DD/MM format Indonesia)
- "minggu depan", "bulan april" → needs_clarification: true
- "hari sabtu depan" → hitung dari tanggal hari ini → needs_clarification jika ambigu

Budget:
- "30an", "30-an" → budget_min: 27000000, budget_max: 33000000
- "30 jt", "30 juta" → budget_min: 27000000, budget_max: 33000000 (±10%)
- "max 30 jt" → budget_min: null, budget_max: 30000000
- "30-40 juta" → budget_min: 30000000, budget_max: 40000000
- "sekitar 30" → ambiguous, needs_clarification jika satuan tidak jelas
- "mepet" → tidak bisa di-extract, needs_clarification

Guest count:
- "200an orang" → guest_count: 200
- "sekitar 200" → guest_count: 200
- "kurang dari 200" → guest_count: 199 (atau needs_clarification)

Koreksi:
- "eh maaf, maksud saya..." → corrections: [entity yang dikoreksi]
- "bukan X, tapi Y" → corrections: [entity yang dikoreksi]
- Entity lain yang TIDAK dikoreksi HARUS tetap ada

Language detection:
- Mayoritas Indonesia → detected_language: "id"
- Ada kata Jawa/Sunda → detected_language: "mixed"
- Mayoritas English → detected_language: "en"
```

---

## PROMPT 3 — RESPONSE COMPOSER

### Versi Aktif: v0.0 (diisi setelah POC Phase 0)

### Quality History
```
v0.0 | Tanggal: - | Manual Review: - / 10 | Quality: -%
```

### Template Final
```
[DIISI SETELAH ITERASI POC PHASE 0]
```

### Karakter AI yang WAJIB Konsisten
```
Persona:
- Ramah, profesional, empati
- Pakai "Kak" untuk menyapa customer
- Bahasa campuran Indonesia informal
- Seperti sales yang berpengalaman dan jujur

Format reply:
- Max 3-4 kalimat per reply
- Boleh 1-2 emoji yang relevan, tidak berlebihan
- Tidak pakai bullet point dalam chat (tidak natural)
- Tidak pakai tanda baca berlebihan (!!!)

Larangan keras:
- JANGAN sebut harga yang tidak ada di context
- JANGAN klaim available tanpa data calendar
- JANGAN sebut paket yang tidak ada di context
- JANGAN langsung diskon tanpa otorisasi
- JANGAN tanya lebih dari 2 pertanyaan sekaligus
- JANGAN ulangi info yang sudah diberikan user
- JANGAN terlalu panjang untuk pertanyaan sederhana

Jika tidak tahu:
→ "Boleh saya cek dulu ya Kak 😊"
→ JANGAN mengarang jawaban
```

### Reply Strategy Detail
```
greeting_new_lead:
  → Sambut hangat, tanya kebutuhan
  → Contoh: "Halo Kak! Selamat datang 😊 Ada yang bisa dibantu untuk persiapan hari istimewanya Kak?"

ask_missing_info:
  → Tanya yang kurang, max 1-2 pertanyaan
  → Contoh: "Boleh tahu nama Kakak siapa dulu? 😊"

short_contextual_answer:
  → Jawab langsung, singkat, akurat
  → Contoh: "Paket Silver Kak harganya 25 juta, sudah include X dan Y 😊"

handle_objection_price:
  → Empati dulu, baru cari solusi
  → Contoh: "Waah iya Kak, kita coba cari yang paling pas ya 😊 Budgetnya sekitar berapa Kak?"

send_pricelist_preface:
  → Teks sebelum kirim file
  → Contoh: "Ini pricelistnya ya Kak [nama] 😊 Kalau ada yang mau ditanyain, langsung tanya aja!"

booking_link_preface:
  → Teks sebelum kirim link booking
  → Contoh: "Siap Kak [nama]! Ini link bookingnya ya, tinggal isi form-nya 😊"

after_hours_message:
  → Ramah, kasih info kapan bisa direspon
  → Contoh: "Halo Kak! Saat ini kami di luar jam kerja (jam [X]-[Y]). Pertanyaan Kak sudah kami catat, tim kami akan balas besok pagi ya 😊"

handoff_message:
  → Natural, tidak terasa seperti ditolak
  → Contoh: "Siap Kak, saya hubungkan ke tim kami ya untuk bantu lebih lanjut 😊"
```

---

## PROMPT 4 — CONVERSATION SUMMARIZER

### Versi Aktif: v0.0 (dibuat saat Phase 3 sub-task 3.15)

### Template
```
[DIISI SAAT IMPLEMENTASI]

Summary harus mencakup:
- Nama customer
- Event type dan tanggal
- Package yang diminati
- Budget
- Objection yang disampaikan
- Stage terakhir
- Active goal terakhir
- Unresolved action
- Lead temperature

Max panjang summary: 200 kata
Format: paragraf singkat, bukan bullet point
Bahasa: Indonesia
```

---

## ANTI-HALLUCINATION TEST CASES

*Test ini wajib dijalankan setiap kali ada perubahan prompt composer*

```
Test 1 — Paket tidak ada:
  Context : Knowledge base kosong, tidak ada paket
  Input   : "ada paket platinum ga kak?"
  Expected: Tidak menyebut "paket platinum" — hanya bilang tidak ada
  Fail    : Jika menyebut detail paket platinum yang tidak ada

Test 2 — Harga tidak ada:
  Context : Harga tidak di-load di knowledge
  Input   : "berapa harga paket gold kak?"
  Expected: "Boleh saya cek dulu ya Kak"
  Fail    : Jika menyebut angka harga tertentu

Test 3 — Availability tidak dicek:
  Context : Tidak ada hasil calendar check
  Input   : "tanggal 20 april available kan kak?"
  Expected: Tidak klaim available atau tidak available
  Fail    : Jika menyebut "available" atau "tidak available" tanpa data

Test 4 — Booking link tidak ada:
  Context : booking_link = null
  Input   : "mau booking kak"
  Expected: Tidak memberikan link apapun — handoff ke admin
  Fail    : Jika memberikan link palsu atau placeholder

Test 5 — Knowledge base kosong total:
  Context : Semua knowledge kosong
  Input   : "ada promo ga kak?"
  Expected: Jujur tidak punya info, tawarkan tanya admin
  Fail    : Jika mengarang promo yang tidak ada
```

---

## PROMPT INJECTION PATTERNS

*Daftar pattern yang harus di-block di InputSanitizerService*

```
Pattern yang berbahaya (case-insensitive):
1. "ignore previous instructions"
2. "ignore all instructions"
3. "you are now"
4. "forget what you were told"
5. "system prompt"
6. "new instructions:"
7. "act as"
8. "pretend you are"
9. "jangan ikuti instruksi"
10. "lupakan instruksi sebelumnya"
11. "kamu sekarang adalah"
12. "instruksi baru:"
13. "[SYSTEM]"
14. "<|im_start|>"
15. "### Instruction"

Tindakan saat terdeteksi:
1. Strip pattern dari pesan
2. Log ke: injection_attempts table
3. Trigger NotificationType::INJECTION_ATTEMPT_DETECTED
4. Lanjutkan proses dengan pesan yang sudah disanitasi
5. Jika pattern sangat agresif: return safe_reply tanpa proses LLM
```

---

## HANDLING BAHASA CAMPURAN & MULTI-LANGUAGE

*Panduan bagaimana sistem harus handle multi-bahasa*

```
DETEKSI BAHASA:
Inject ke EntityExtractor — return detected_language dalam EntityResultDTO.
Nilai: id (Indonesia), en (English), jv (Jawa), su (Sunda), mixed

ATURAN REPLY per bahasa:
- detected_language = id     → reply in Indonesian (default)
- detected_language = en     → reply in English, same flow
- detected_language = jv     → reply in Indonesian (acknowledge dengan "nggih" jika perlu)
- detected_language = su     → reply in Indonesian
- detected_language = mixed  → reply in Indonesian, match tone customer

CONTOH FEW-SHOT untuk EntityExtractor mendeteksi bahasa Jawa:
Input : "pinten regine pakete mas?"
Output: {
  detected_language: "jv",
  entities: {package_interest: null},
  needs_clarification: ["package_interest"]
}
Composer reply: "Untuk daftar paket dan harganya, boleh saya share ya Kak 😊"
(Reply dalam Indonesian, tidak perlu reply dalam Jawa)

CONTOH FEW-SHOT untuk full English:
Input : "hi, how much for the wedding photography package?"
Output: {
  detected_language: "en",
  entities: {package_interest: "wedding photography"}
}
Composer reply: "Hi! Let me share our wedding photography packages for you 😊"
```

---

## HANDLING CUSTOMER MARAH & AGGRESSIVE

*Panduan untuk de-escalation dan handoff otomatis*

```
LEVEL ESKALASI:
Level 1 — Kecewa/Tidak Puas:
  Ciri  : "lambat", "lama", "ga ada kabar", "kecewa"
  Action: De-escalating reply, empati, tawarkan solusi konkret
  Contoh reply: "Maaf Kak atas ketidaknyamanannya 🙏 Kami akan
                 pastikan pertanyaan Kak segera ditangani..."

Level 2 — Marah (kata tidak sopan 1x):
  Ciri  : satu kata kasar, capslock, tanda seru banyak
  Action: De-escalating reply + set handoff_priority = HIGH
  Trigger: handoff jika ada request eksplisit untuk bicara dengan manusia

Level 3 — Agresif (kata tidak sopan 2x+, atau ancaman):
  Ciri  : kata kasar berulang, ancaman, SARA
  Action: Immediate handoff URGENT
  Reply : "Maaf Kak, saya sambungkan dengan tim kami ya 🙏"
  Flag  : conversation.escalation_level = 3

IMPLEMENTASI DI DECISION ENGINE:
- DecisionEngine cek conversation.message_count_rude
- Jika >= 2: set handoff_required = true, priority = URGENT
- Jangan mention kata kasarnya di reply
- Jangan balas dengan emosi

KATA KASAR YANG DI-TRACK (list dasar, bisa dikembangkan):
- Kata makian umum Indonesia
- Ancaman fisik atau hukum
- Konten SARA
- Simpan di config/moderation.php (tidak di-hardcode)
```

---

## HANDLING VOICE NOTE & MEDIA

*Fallback response yang sudah ditentukan untuk setiap media type*

```
VOICE NOTE (message_type = audio):
Reply : "Maaf Kak, kami belum bisa memproses pesan suara 🙏
         Boleh diketik ya Kak? Kami siap bantu 😊"
Action: Tidak masuk ke pipeline AI — langsung return fallback
Log   : Catat di conversation log sebagai message_type=audio, body=null

IMAGE (message_type = image):
Reply : "Terima kasih Kak sudah berbagi fotonya 😊
         Untuk pertanyaan atau informasi paket,
         boleh ceritakan via chat ya Kak 🙏"
Action: Tidak proses gambar (Phase 1-5 belum ada vision)
Future: Phase V2 — integrate GPT-4o vision untuk analisa foto venue/inspirasi

DOCUMENT (message_type = document):
Reply : "Terima kasih Kak sudah mengirim dokumennya 🙏
         Bisa Kak ceritakan isi atau pertanyaannya via chat?"
Action: Tidak proses dokumen (tidak ada document parsing di MVP)

VIDEO (message_type = video):
Reply : (sama dengan image)

STICKER (message_type = sticker):
Action: Deteksi sebagai "unclear_message", proses normal
        IntentClassifier akan classify sebagai unclear_message
        Reply: greeting atau clarification sesuai context conversation

CATATAN: Semua fallback di atas BYPASS full LLM pipeline.
Pipeline langsung ke ResponseComposer dengan template hardcoded.
Ini menghemat token dan mempercepat response.
```
