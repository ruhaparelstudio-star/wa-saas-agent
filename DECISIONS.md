# DECISIONS.md — Decision Log, Parking Lot & Technical Debt
# Catat semua keputusan penting, ide yang muncul di tengah jalan,
# dan technical debt yang perlu di-revisit nanti.

---

## CARA PENGGUNAAN

```
Decision Log  : Keputusan arsitektur yang sudah diambil dan alasannya
Parking Lot   : Ide yang muncul tapi bukan scope sekarang — jangan diimplementasi dulu
Technical Debt: Shortcut yang sengaja diambil dan perlu diperbaiki nanti
```

---

## DECISION LOG

### ARCH-001 — Filament sebagai Admin Panel (bukan Next.js)
```
Tanggal   : Initial setup
Keputusan : Pakai Filament 3.x untuk semua dashboard admin
Alasan    : Solo dev, satu stack PHP lebih mudah maintain,
            Filament generate UI otomatis dari model,
            hemat 2-3 minggu development
Trade-off : UI kurang customizable dibanding Next.js
Revisit   : Setelah ada revenue dan bisa hire frontend dev
```

### ARCH-002 — Decision Engine = PHP Murni, LLM Hanya Compose
```
Tanggal   : Initial setup
Keputusan : Semua keputusan bisnis di PHP rule engine
            LLM hanya untuk: classify, extract, compose
Alasan    : LLM non-deterministic untuk keputusan bisnis,
            PHP rule engine bisa di-test dengan unit test,
            audit trail lebih jelas
Trade-off : Rule engine perlu di-maintain saat ada rule baru
Revisit   : Tidak — ini prinsip fundamental yang tidak boleh diubah
```

### ARCH-003 — PostgreSQL + pgvector (bukan MySQL)
```
Tanggal   : Initial setup
Keputusan : PostgreSQL 16 dengan pgvector extension
Alasan    : pgvector native tanpa service tambahan,
            PostgreSQL lebih robust untuk data kompleks,
            JSON handling lebih baik
Trade-off : Tim harus familiar dengan PostgreSQL
Revisit   : Tidak perlu
```

### ARCH-004 — Full-text Search untuk MVP, pgvector untuk V2
```
Tanggal   : Initial setup
Keputusan : Mulai dengan PostgreSQL tsvector full-text search
            Tambah pgvector embedding setelah knowledge base besar
Alasan    : Mengurangi kompleksitas awal, embedding cost,
            cold start problem
Trade-off : Search kurang akurat untuk query semantik
Revisit   : Phase 3 — evaluasi apakah perlu pgvector atau tsvector cukup
```

### ARCH-005 — Wedding Industry Dulu, Multi-industry Nanti
```
Tanggal   : Initial setup
Keputusan : MVP fokus 100% wedding industry
            Intent, entity, flow semua wedding-specific
Alasan    : Mengurangi kompleksitas 60%,
            validasi product-market fit dulu,
            conversation lebih akurat dengan context spesifik
Trade-off : Tidak bisa onboard tenant non-wedding di MVP
Revisit   : V2 — setelah ada 5+ tenant wedding aktif
```

---

## PARKING LOT

*Ide yang muncul tapi bukan scope MVP. Catat di sini, jangan diimplementasi dulu.*

```
IDEA-001 : Multi-language support (Bahasa Jawa, Sunda, English)
           Muncul saat: design entity extractor
           Priority: V2
           Notes: Perlu separate prompt per language atau language detection

IDEA-002 : Voice note transcription via Whisper API
           Muncul saat: design WA gateway
           Priority: V2
           Notes: Customer sering pakai voice note, bisa jadi differentiator

IDEA-003 : Instagram DM integration
           Muncul saat: design channel architecture
           Priority: V2
           Notes: ChannelGatewayInterface sudah siap, tinggal implementasi adapter

IDEA-004 : AI-generated portfolio suggestion
           Muncul saat: design knowledge base
           Priority: V3
           Notes: AI bantu vendor buat caption/deskripsi paket yang menarik

IDEA-005 : Customer sentiment analysis dashboard
           Muncul saat: design analytics
           Priority: V2
           Notes: Dari conversation log, bisa detect sentiment per customer
```

---

## TECHNICAL DEBT

*Shortcut yang sengaja diambil. Harus diperbaiki sebelum V2.*

```
DEBT-001 : [Belum ada — akan diisi saat development]
Format   :
  ID      : DEBT-XXX
  Sub-task: X.X
  Shortcut: [apa yang dishortcut]
  Alasan  : [kenapa diambil shortcut]
  Fix     : [cara memperbaiki yang benar]
  Deadline: [sebelum phase/version berapa]
```

---

## WEEKLY REVIEW CHECKLIST

*Jalankan setiap akhir minggu*

```
Minggu ke: ___
Tanggal  : ___

Progress:
[ ] Berapa sub-task selesai minggu ini? ___
[ ] Apakah sesuai target timeline?
[ ] Ada blocker yang perlu diselesaikan?

Quality:
[ ] Accuracy score masih di atas threshold?
[ ] Ada test yang fail dan belum di-fix?
[ ] Ada technical debt baru yang perlu dicatat?

Decision:
[ ] Ada keputusan arsitektur baru yang perlu dicatat?
[ ] Ada ide di Parking Lot yang ternyata perlu diimplementasi sekarang?

Next week plan:
[ ] Sub-task apa yang akan dikerjakan?
[ ] Ada dependency yang perlu diselesaikan dulu?
[ ] Ada yang perlu dikomunikasikan?
```

---

## SUCCESS METRICS

*Kapan sistem dianggap berhasil*

### MVP Success (Phase 5 selesai)
```
[ ] 1 tenant wedding aktif menggunakan sistem
[ ] 30/30 benchmark skenario PASS
[ ] Intent accuracy > 85% di production
[ ] Response time < 3 detik dari pesan masuk sampai reply
[ ] Zero hallucination critical dalam 7 hari monitoring
[ ] Admin bisa takeover dan resume dengan lancar
[ ] Log viewer menampilkan semua intermediate result
```

### Product-Market Fit (3 bulan setelah launch)
```
[ ] 5+ tenant aktif
[ ] > 100 conversation per hari total
[ ] < 10% conversation yang perlu handoff admin
[ ] Tenant retention > 80% setelah 1 bulan
[ ] NPS score dari tenant > 7
```

### Scale Ready (sebelum V2)
```
[ ] 20+ tenant aktif
[ ] System uptime > 99%
[ ] P95 response time < 5 detik
[ ] Zero data breach
[ ] Siap untuk multi-industry expansion
```

---

## DEFINISI OF DONE (DoD)

*Sebuah sub-task dianggap DONE jika dan hanya jika semua ini terpenuhi:*

```
CODE:
✅ Semua file yang disebutkan di Coding Prompt sudah dibuat
✅ Semua method/function yang disebutkan sudah diimplementasi
✅ php artisan test --filter=[NamaTest] → 100% PASS
✅ php -l [semua file baru] → No syntax errors
✅ Tidak ada hardcode tenant_id, API key, atau secret

ARSITEKTUR:
✅ Semua model extend BaseModel atau TenantBaseModel
✅ Semua query tenant data ada filter tenant_id
✅ Unit test menggunakan MockLlmAdapter (bukan real OpenAI)
✅ Semua interface diimplementasi lengkap

DOKUMENTASI:
✅ PROGRESS.md sudah diupdate dengan [x] DONE
✅ File Registry di PROGRESS.md sudah diupdate (path + methods exposed)
✅ KANBAN-PHASE-X.md sudah dicentang [x]
✅ Jika ada keputusan arsitektur baru: catat di DECISIONS.md

VERSION CONTROL:
✅ git commit sudah dibuat dengan message descriptive
✅ Tidak ada TODO comment yang belum diselesaikan
    (kecuali sudah dicatat di DECISIONS.md technical debt)

SATU item pun yang tidak ✅ = sub-task BELUM DONE.
```

---

## API DOCUMENTATION PLAN

```
MVP: Tidak perlu Swagger (internal use saja)
     Dokumentasi cukup di setiap routes file sebagai komentar

Format komentar di routes:
/**
 * POST /api/v1/webhook/inbound
 * Internal only — from WA Gateway
 * Headers: X-Internal-Secret: {secret}
 * Body: InboundMessageDTO
 * Response: {received: true}
 */

Pre-launch V1 (setelah 5+ tenant aktif):
→ Generate Swagger dari route annotations
→ Publish di: https://[domain]/api-docs
→ Untuk tenant yang ingin custom integration

Tools: darkaonline/l5-swagger atau scribe
Tambahkan ke Parking Lot dulu, implement saat ada kebutuhan nyata.
```

---

## TENANT ONBOARDING DOCUMENTATION PLAN

```
MVP: Panduan sederhana dalam bentuk PDF
     Dibuat di luar sistem, tidak perlu in-app tutorial

Isi panduan:
1. Cara setup WA agent (scan QR)
2. Cara isi knowledge base (paket, FAQ, pricelist)
3. Cara set business hours dan after-hours message
4. Cara lihat conversation dan lead di inbox
5. Cara takeover dan resume conversation
6. Cara baca analytics dan laporan

Format: Loom video + PDF checklist
Timeline: Buat setelah Phase 5, sebelum onboarding tenant pertama

Simpan di: Google Drive, share link via email onboarding
```
