# RECOVERY.md — Error Recovery Protocol
# Panduan ketika sesuatu tidak berjalan sesuai rencana
# Baca ini ketika: stuck, Claude Code salah arah, context penuh, atau ada error

---

## SKENARIO 1 — Claude Code Mulai Coding yang Tidak Diminta

**Tanda-tanda:**
- Claude Code langsung coding tanpa konfirmasi
- Claude Code membuat file yang tidak ada di prompt
- Claude Code menambahkan fitur yang tidak diminta

**Tindakan:**
```
1. Ketik "STOP" di Claude Code
2. Ketik: "Hapus semua yang baru kamu buat di sesi ini"
3. Konfirmasi file sudah dihapus dengan: ls -la [folder]
4. Mulai ulang dengan Context Prompt yang lebih spesifik:
   "Kamu hanya boleh membuat file yang DISEBUTKAN EKSPLISIT
    di Coding Prompt. Tidak boleh tambah file lain.
    Konfirmasi kamu mengerti sebelum mulai."
```

---

## SKENARIO 2 — Context Window Penuh di Tengah Sub-task

**Tanda-tanda:**
- Claude Code mulai "lupa" instruksi awal
- Response tidak konsisten dengan sebelumnya
- Claude Code menjawab generik, tidak spesifik ke project

**Tindakan:**
```
1. JANGAN lanjutkan sesi ini
2. Cek file apa yang sudah dibuat: ls -la [folder yang sedang dikerjakan]
3. Catat di PROGRESS.md: "Sub-task X.X — INTERRUPTED — file yang sudah ada: ..."
4. Tutup sesi Claude Code
5. Buka sesi baru
6. Paste Context Prompt dari KANBAN
7. Tambahkan: "Sub-task ini sudah sebagian dikerjakan.
               File yang sudah ada: [list file].
               Lanjutkan dari bagian yang belum selesai saja.
               Jangan overwrite file yang sudah ada."
8. Verifikasi dengan: "Sebutkan file apa saja yang perlu kamu buat
                       dan mana yang sudah ada."
```

---

## SKENARIO 3 — File Dibuat tapi Tidak Lengkap

**Tanda-tanda:**
- Claude Code bilang "sudah selesai" tapi file terasa singkat
- Test fail karena method tidak ada
- Class ada tapi interface tidak diimplementasi

**Tindakan:**
```
1. Baca file yang bermasalah: cat [path/file.php]
2. Bandingkan dengan requirement di KANBAN
3. Daftar apa yang kurang
4. Di sesi yang sama atau sesi baru, paste:
   "Baca file ini: [path/file.php]
    Yang masih kurang dibandingkan requirement:
    [list item yang kurang]
    Lengkapi bagian yang kurang saja.
    Jangan ubah yang sudah benar."
```

---

## SKENARIO 4 — Test Fail Setelah Sub-task Selesai

**Tanda-tanda:**
- php artisan test menampilkan error
- Test yang sebelumnya pass sekarang fail

**Tindakan:**
```
1. Jalankan: php artisan test --filter=[NamaTest] -v
   untuk lihat detail error
2. Baca error message dengan teliti
3. Cek apakah error dari:
   a. File yang baru dibuat → fix di sesi ini
   b. File dari sub-task sebelumnya yang ter-break → rollback
4. Jika ter-break file lama:
   git diff HEAD~1 [file yang bermasalah]
   Lihat perubahan apa yang menyebabkan break
5. Jangan lanjut ke sub-task berikutnya sebelum semua test pass
6. Catat di PROGRESS.md: "Known issue: [deskripsi] — Fixed: [solusi]"
```

---

## SKENARIO 5 — Claude Code Membuat Nama File/Class yang Berbeda

**Tanda-tanda:**
- File dibuat dengan nama berbeda dari yang diinstruksikan
- Class name tidak sesuai Naming Convention di CLAUDE.md
- Namespace berbeda

**Tindakan:**
```
1. JANGAN gunakan file yang salah nama
2. Di sesi yang sama:
   "File yang kamu buat salah nama.
    Yang benar: [nama yang sesuai CLAUDE.md]
    Yang kamu buat: [nama yang salah]
    
    Rename dan update semua reference:
    mv [nama salah] [nama benar]
    Update namespace di dalam file.
    Update semua import di file lain yang menggunakan class ini."
3. Verifikasi: php artisan → tidak ada error
```

---

## SKENARIO 6 — Docker Container Tidak Bisa Start

**Tanda-tanda:**
- docker compose up gagal
- Container exit immediately
- Port conflict error

**Tindakan:**
```bash
# Cek status container
docker compose ps

# Lihat log container yang bermasalah
docker compose logs [nama-service]

# Port conflict: cek port yang dipakai
sudo lsof -i :[port]
# Kill process yang pakai port tersebut
sudo kill -9 [PID]

# Reset docker state jika semua gagal
docker compose down -v
docker system prune -f
docker compose up -d --build

# Jika PostgreSQL tidak bisa start karena volume corrupt
docker compose down
docker volume rm [nama_volume_postgres]
docker compose up -d
docker compose exec app php artisan migrate --seed
```

---

## SKENARIO 7 — Accuracy Test Tiba-tiba Turun

**Tanda-tanda:**
- Intent accuracy turun > 5% dari baseline
- Entity accuracy turun > 5%
- Setelah perubahan prompt atau code

**Tindakan:**
```
1. STOP — jangan lanjut sub-task berikutnya
2. Identifikasi perubahan terakhir:
   git diff HEAD~1 PROMPTS.md
   git diff HEAD~1 app/Modules/AgentCore/
3. Rollback perubahan prompt ke versi sebelumnya:
   git checkout HEAD~1 PROMPTS.md
4. Jalankan accuracy test lagi
5. Bandingkan hasil
6. Jika accuracy kembali normal: perubahan prompt yang menyebabkan
7. Iterasi prompt dengan lebih hati-hati
8. Catat di PROMPTS.md: "Perubahan X menyebabkan accuracy turun"
```

---

## SKENARIO 8 — Baileys Session Hilang Setelah Restart

**Tanda-tanda:**
- WA agent status DISCONNECTED setelah docker restart
- Session file tidak ada

**Tindakan:**
```bash
# Cek volume wa_sessions
docker volume inspect wa_sessions

# Cek isi session directory di wa-gateway container
docker compose exec wa-gateway ls -la /app/sessions/

# Jika kosong: session memang hilang, perlu scan QR ulang
# Ini normal behavior untuk Baileys

# Untuk mencegah di masa depan:
# Pastikan volume mount benar di docker-compose.yml:
# wa-gateway:
#   volumes:
#     - wa_sessions:/app/sessions

# Restart sequence yang benar:
docker compose stop wa-gateway
docker compose start wa-gateway
# Tunggu 10 detik
curl http://localhost:3001/health
```

---

## SKENARIO 9 — OpenAI API Rate Limit atau Error

**Tanda-tanda:**
- Error 429 Too Many Requests
- Test suite lambat atau hang
- Timeout di LLM calls

**Tindakan:**
```
Development (bukan test accuracy):
→ Gunakan LlmMockAdapter, bukan real API
→ Set di .env.testing: LLM_PROVIDER=mock

Accuracy testing:
→ Tunggu 1 menit sebelum retry
→ Maksimal 3x accuracy test per hari
→ Jika masih limit: lanjutkan besok

Production issue:
→ Cek OpenAI status: https://status.openai.com
→ Aktifkan fallback response di config:
   LLM_FALLBACK_ENABLED=true
   LLM_FALLBACK_MESSAGE="Maaf, ada kendala teknis. Tim kami akan segera membalas."
```

---

## SKENARIO 10 — Git Merge Conflict

**Tanda-tanda:**
- git pull menampilkan conflict
- File memiliki <<<<<<< markers

**Tindakan:**
```bash
# Lihat file yang conflict
git status

# Untuk setiap file conflict:
# Buka di VS Code, pilih versi yang benar
# Atau gunakan:
git checkout --ours [file]   # pakai versi local
git checkout --theirs [file] # pakai versi remote

# Setelah resolve semua conflict:
git add .
git commit -m "fix: resolve merge conflict di [file]"

# Jika terlalu kompleks: abort dan minta bantuan
git merge --abort
```

---

## CHECKLIST SEBELUM LANJUT SUB-TASK BERIKUTNYA

Jalankan ini setelah setiap sub-task selesai:

```bash
# 1. Test pass
php artisan test → semua pass? ✅

# 2. File ada dan lengkap
cat [file yang dibuat] | wc -l → lebih dari 10 baris? ✅

# 3. Tidak ada syntax error
php -l [file yang dibuat] → No syntax errors? ✅

# 4. PROGRESS.md terupdate
grep "[x]" PROGRESS.md | tail -1 → sub-task terakhir sudah [x]? ✅

# 5. Git commit dibuat
git log --oneline -1 → ada commit baru? ✅

# Jika semua ✅: lanjut sub-task berikutnya
# Jika ada ❌: selesaikan dulu sebelum lanjut
```

---

## SKENARIO 11 — Deploy saat Ada Customer Aktif Chat

**Tanda-tanda:**
- Ingin deploy update di jam operasional
- Khawatir conversation terputus

**Tindakan:**
```bash
# Urutan deploy yang aman (zero-downtime untuk MVP):

# 1. Pastikan Laravel queue selesai proses job aktif
docker compose exec app php artisan queue:pause
# Tunggu sampai semua job selesai:
docker compose exec app php artisan horizon:status
# Tunggu status "idle"

# 2. Buat maintenance mode yang custom
docker compose exec app php artisan down \
  --message="Kami sedang maintenance sebentar. Mohon tunggu 🙏" \
  --retry=60

# 3. Jalankan migration
docker compose exec app php artisan migrate --force

# 4. Deploy code baru (restart container)
docker compose up -d --build app

# 5. Resume queue
docker compose exec app php artisan up
docker compose exec app php artisan queue:resume

# 6. Verifikasi
curl http://localhost:8080/health
# Expected: {"status":"ok"}

# Catatan:
# - Pesan yang masuk saat maintenance: akan masuk queue dan diproses setelah up
# - WA Gateway (Node.js) tidak perlu restart kecuali ada perubahan di wa-gateway/
# - Migration yang tidak backward-compatible: buat dulu sebagai nullable, deploy,
#   baru ubah menjadi required di migration berikutnya
```

---

## SKENARIO 12 — Migration Gagal di Production

**Tanda-tanda:**
- php artisan migrate error di tengah jalan
- Database dalam kondisi partial migration
- Beberapa tabel sudah berubah, beberapa belum

**Tindakan:**
```bash
# 1. Jangan panik — cek migration mana yang gagal
docker compose exec app php artisan migrate:status

# 2. Lihat error yang terjadi
docker compose logs app | grep -i "migration\|error" | tail -20

# 3. Jika migration bisa di-rollback:
docker compose exec app php artisan migrate:rollback --step=1

# 4. Jika tidak bisa rollback (data sudah ada):
# Buka psql langsung
docker compose exec postgres psql -U postgres -d wa_saas
# Analisa kondisi tabel
\dt
# Perbaiki manual jika perlu

# 5. Fix migration file yang error
# Jalankan kembali:
docker compose exec app php artisan migrate

# PENCEGAHAN:
# Selalu test migration di lokal sebelum production
# Gunakan: php artisan migrate:fresh --seed (di lokal)
# Jangan pernah edit migration file yang sudah di-commit
# Selalu buat migration baru untuk perubahan
```

---

## SKENARIO 13 — Monitoring Alert: Sistem Down

**Setup monitoring alert (pasang setelah Phase 5):**
```bash
# Option 1: UptimeRobot (gratis)
# - Daftar di https://uptimerobot.com
# - Tambahkan monitor: http://[production-url]/health
# - Set alert via email + Telegram

# Option 2: Telegram Bot Alert
# Buat bot Telegram, dapatkan chat_id
# Tambahkan ke .env:
ALERT_TELEGRAM_TOKEN=xxx
ALERT_TELEGRAM_CHAT_ID=xxx

# Alert yang wajib dikonfigurasi:
# - Health endpoint down > 1 menit → CRITICAL
# - LLM error rate > 10% dalam 5 menit → WARNING
# - Queue size > 100 jobs → WARNING
# - WA agent disconnect > 5 menit → INFO
# - Injection attempt terdeteksi → INFO

# Threshold alert di config/monitoring.php:
ALERT_QUEUE_SIZE_THRESHOLD=100
ALERT_LLM_ERROR_RATE_THRESHOLD=10
ALERT_RESPONSE_TIME_THRESHOLD_MS=5000
```

---

## SKENARIO 14 — OpenAI Downtime Total (LLM Fallback)

**Tanda-tanda:**
- Semua LLM call gagal
- OpenAI status page: incident reported
- Customer tetap chat

**Tindakan:**
```bash
# 1. Aktifkan fallback mode via .env atau config
LLM_FALLBACK_ENABLED=true
LLM_FALLBACK_MESSAGE="Maaf Kak, asisten kami sedang offline sebentar. Tim kami akan segera membalas dalam 1-2 jam. Terima kasih atas kesabarannya 🙏"

# Atau via Filament (tanpa restart):
# Settings → System → LLM Fallback → Enable

# Efek fallback mode:
# - Pipeline tidak call LLM
# - Semua incoming message dapat fallback_message
# - Lead tetap dicatat (conversation dibuat)
# - Admin mendapat notifikasi setiap ada customer baru
# - Tenant admin bisa manual reply dari Filament inbox

# 2. Monitor status OpenAI:
# https://status.openai.com

# 3. Setelah OpenAI normal:
# Matikan fallback mode
# Queue akan proses ulang pesan yang masuk saat fallback
# TIDAK perlu replay manual — queue sudah handle
```

---

## SKENARIO 15 — Baileys WA Account Kena Ban/Restrict

**Tanda-tanda:**
- WaAccountStatus berubah ke BANNED_OR_RESTRICTED
- Tidak bisa kirim pesan meskipun session masih ada
- Error dari Baileys: "Connection Failure"

**Tindakan:**
```
1. Jangan coba reconnect berkali-kali — ini memperburuk ban
2. Informasikan tenant via email/Filament notification:
   "WA agent Anda terdeteksi dibatasi oleh WhatsApp.
    Kemungkinan penyebab: pengiriman pesan massal atau spam.
    Solusi: Gunakan nomor WA baru, preferably WA Business."

3. Pencegahan di masa depan:
   - Jangan send pesan ke lebih dari 50 nomor baru per hari
   - Jangan send pesan identik ke banyak nomor (broadcast)
   - Gunakan nomor WA yang sudah berumur 3+ bulan
   - Nomor WA Business lebih tahan ban

4. Untuk recovery: tenant harus ganti nomor WA
   - Di Filament: WA Accounts → Add New Account → Scan QR
   - Nomor lama bisa dicoba restore tapi tidak dijamin
```

---

## KONTAK DARURAT KETIKA BENAR-BENAR STUCK

```
1. Buka sesi Claude baru (bukan Claude Code)
2. Paste: CLAUDE.md + PROGRESS.md + file yang bermasalah
3. Tanya: "Saya stuck di sub-task X.X. Ini file yang bermasalah.
           Apa yang salah dan bagaimana cara memperbaikinya?"
4. Setelah dapat solusi, kembali ke Claude Code untuk implementasi

Jangan coba fix di Claude.ai langsung —
selalu implementasi di Claude Code untuk konsistensi.
```
