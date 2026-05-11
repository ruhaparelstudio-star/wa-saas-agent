# KANBAN.md — Master Index
# WA SaaS AI Sales Agent

---

## STATUS OVERVIEW

```
Phase 0 — POC               : [ ] 0/5  complete → KANBAN-PHASE-0.md
Phase 1 — Foundation        : [ ] 0/10 complete → KANBAN-PHASE-1.md
Phase 2 — Knowledge         : [ ] 0/7  complete → KANBAN-PHASE-2.md (generate setelah Phase 1)
Phase 3 — AI Pipeline       : [ ] 0/15 complete → KANBAN-PHASE-3.md (generate setelah Phase 2)
Phase 4 — WhatsApp          : [ ] 0/8  complete → KANBAN-PHASE-4.md (generate setelah Phase 3)
Phase 5 — Features & Prod   : [ ] 0/9  complete → KANBAN-PHASE-5.md (generate setelah Phase 4)
```

---

## URUTAN PENGERJAAN

```
1. Mulai dengan KANBAN-PHASE-0.md
2. Setelah Integration Checkpoint Phase 0 OPEN → lanjut Phase 1
3. Setelah Integration Checkpoint Phase 1 OPEN → generate dan mulai Phase 2
4. Dan seterusnya...

JANGAN lewati phase atau sub-task.
JANGAN mulai phase berikutnya sebelum gate OPEN.
```

---

## CARA GENERATE KANBAN PHASE BERIKUTNYA

Setelah Integration Checkpoint phase N selesai, jalankan di Claude Code:

```
Baca file berikut:
- CLAUDE.md
- PROGRESS.md
- KANBAN-PHASE-[N].md (sebagai template)
- Semua file yang sudah dibuat di Phase N (dari File Registry PROGRESS.md)

Generate KANBAN-PHASE-[N+1].md dengan:
- Format yang sama persis dengan KANBAN-PHASE-N.md
- Setiap sub-task punya: Context Prompt, Coding Prompt, QA Prompt, Update Prompt
- Setiap Context Prompt menyebutkan file spesifik yang harus dibaca
- Setiap Coding Prompt menyebutkan Depends On check yang harus diverifikasi
- Exit gate Integration Checkpoint yang jelas dan terukur
```

---

## FILE STRUCTURE

```
project/
├── CLAUDE.md              ← Baca otomatis Claude Code — konteks permanen
├── PROGRESS.md            ← Status terkini — update setiap sub-task
├── KANBAN.md              ← File ini — index saja
├── KANBAN-PHASE-0.md      ← Prompt detail Phase 0
├── KANBAN-PHASE-1.md      ← Prompt detail Phase 1
├── KANBAN-PHASE-2.md      ← (generate setelah Phase 1)
├── KANBAN-PHASE-3.md      ← (generate setelah Phase 2)
├── KANBAN-PHASE-4.md      ← (generate setelah Phase 3)
├── KANBAN-PHASE-5.md      ← (generate setelah Phase 4)
├── PROMPTS.md             ← Prompt library dengan versioning
├── BENCHMARK.md           ← 30 skenario wajib lulus sebelum launch
├── DECISIONS.md           ← Decision log, parking lot, technical debt
├── SETUP.md               ← Setup WSL2 + Docker + Claude Code dari nol
├── RECOVERY.md            ← Error recovery protocol
├── GITFLOW.md             ← Git strategy dan commit convention
├── poc/                   ← Phase 0 only
├── laravel-app/           ← Laravel backend
├── wa-gateway/            ← Node.js Baileys
├── docker/                ← Docker configs
└── tests/
    └── conversation-data/
        ├── intent-test-cases.json    ← 50 test case intent
        ├── entity-test-cases.json    ← 30 test case entity
        ├── decision-test-cases.json  ← 40 test case decision
        └── e2e-scenarios.json        ← 10 skenario e2e
```

---

## ATURAN YANG TIDAK BOLEH DILANGGAR

```
1. Satu sesi Claude Code = satu sub-task
2. php artisan test harus 100% pass sebelum lanjut
3. Gate harus OPEN sebelum pindah phase
4. Commit setelah setiap sub-task
5. Tag setelah setiap Integration Checkpoint
6. PROGRESS.md harus diupdate sebelum tutup sesi
7. File Registry harus akurat
```

---

## REFERENSI FILE DOKUMEN

```
CLAUDE.md          ← Context utama — dibaca Claude Code setiap sesi
PROGRESS.md        ← Status semua sub-task + file registry
KANBAN.md          ← Index ini
KANBAN-PHASE-0.md  ← Prompt lengkap Phase 0 (POC)
KANBAN-PHASE-1.md  ← Prompt lengkap Phase 1 (Foundation)
PROMPTS.md         ← Library prompt + versioning + anti-hallucination test
BENCHMARK.md       ← 30 skenario wajib pass sebelum launch
DECISIONS.md       ← Decision log + parking lot + technical debt + DoD
GITFLOW.md         ← Git strategy + commit convention
SETUP.md           ← Setup WSL2 + Docker + Claude Code dari nol
RECOVERY.md        ← Error recovery protocol (15 skenario)
SECURITY.md        ← Keamanan + PII + prompt injection + production checklist
OPS.md             ← Operasional production + monitoring + scaling + backup
scripts/
  check_accuracy_regression.php  ← Regression check otomatis
tests/conversation-data/
  intent-test-cases.json         ← 50 test case intent
  entity-test-cases.json         ← 30 test case entity
  decision-test-cases.json       ← 40 test case decision
  e2e-scenarios.json             ← 10 skenario end-to-end
```

---

## QUICK REFERENCE — ACCURACY TARGETS

```
Intent Accuracy    : > 85%  (diukur dari 50 test case, 3x run)
Entity Accuracy    : > 85%  (diukur dari 30 test case, 3x run)
Decision Accuracy  : > 90%  (diukur dari 40 test case, 3x run)
Response Quality   : > 80%  natural (manual review)
Benchmark Score    : 30/30  (sebelum launch)
```

---

## QUICK REFERENCE — GIT TAGS

```
v0.1-poc-complete       ← Phase 0 selesai
v0.2-foundation-complete← Phase 1 selesai
v0.3-knowledge-complete ← Phase 2 selesai
v0.4-pipeline-complete  ← Phase 3 selesai
v0.5-whatsapp-complete  ← Phase 4 selesai
v1.0-production         ← Phase 5 selesai, siap launch
```
