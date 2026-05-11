# PROGRESS.md — WA SaaS AI Sales Agent
# Di-update OTOMATIS oleh Claude Code setiap sub-task selesai.
# Baca file ini di awal setiap sesi untuk tahu status terkini.

---

## STATUS TERKINI

```
Phase Aktif    : Phase 0 — Proof of Concept
Sub-task Aktif : 0.2 — Prompt Engineering Intent Classifier
Last Updated   : 2026-05-11
Git Branch     : dev
Last Commit    : feat: POC script standalone untuk validasi AI pipeline
Last Tag       : (belum ada)
```

---

## OVERALL PROGRESS

```
Phase 0 : 1 / 5  sub-task  [▓░░░░]
Phase 1 : 0 / 10 sub-task  [ ]
Phase 2 : 0 / 7  sub-task  [ ]
Phase 3 : 0 / 15 sub-task  [ ]
Phase 4 : 0 / 8  sub-task  [ ]
Phase 5 : 0 / 9  sub-task  [ ]
─────────────────────────────────
Total   : 0 / 54 sub-task
```

---

## ACCURACY BASELINE

```
Metric              | Current | Target  | Status
--------------------|---------|---------|--------
Intent Accuracy     | -       | > 85%   | ⏳
Entity Accuracy     | -       | > 85%   | ⏳
Decision Accuracy   | -       | > 90%   | ⏳
Response Quality    | -       | > 80%   | ⏳
Benchmark Score     | -       | 30/30   | ⏳
```

*Diupdate setiap kali accuracy test dijalankan. Catat tanggal dan versi prompt.*

---

## PHASE 0 — PROOF OF CONCEPT

### Sub-task 0.1 — POC Script PHP Standalone
```
Status        : [x] DONE — 2026-05-11
Files Created : poc/poc_conversation.php
Commit        : feat: POC script standalone untuk validasi AI pipeline
Notes         : PocLlmClient (classifyIntent, extractEntities, composeReply),
                PocDecisionEngine (PHP rules only, NO LLM), mock knowledge 3 paket,
                test runner 8 pesan dengan entity persistence antar turn.
                Jalankan: OPENAI_API_KEY=sk-xxx php poc/poc_conversation.php
```

### Sub-task 0.2 — Prompt Engineering Intent Classifier
```
Status         : [ ] TODO
Files Modified : PROMPTS.md
Prompt Version : -
Accuracy Run 1 : - %
Accuracy Run 2 : - %
Accuracy Run 3 : - %
Average        : - %
Gate           : [ ] >= 85% sebelum lanjut 0.3
Notes          : -
```

### Sub-task 0.3 — Prompt Engineering Entity + Composer
```
Status               : [ ] TODO
Files Modified       : PROMPTS.md
Entity Prompt Version: -
Entity Accuracy      : - %
Composer Version     : -
Composer Quality     : - / 10 manual review
Hallucination Test   : [ ] PASS
Notes                : -
```

### Sub-task 0.4 — POC Conversation Test (10 Skenario)
```
Status          : [ ] TODO
Skenario Passed : - / 10
Failed List     : -
Notes           : -
```

### Sub-task 0.5 — POC Security Test (Prompt Injection)
```
Status          : [ ] TODO
Injection Tests : - / 5
Sanitizer Works : [ ]
Notes           : -
```

### Integration Checkpoint Phase 0
```
Status              : [ ] TODO
Intent Accuracy     : - %
Entity Accuracy     : - %
Skenario Passed     : - / 10
Injection Protected : [ ]
Git Tag             : -
Gate                : [ ] OPEN untuk Phase 1
```

---

## PHASE 1 — CONTRACTS & FOUNDATION

### Sub-task 1.1 — Project Laravel + Struktur Modul
```
Status        : [ ] TODO
Files Created : -
Command Works : [ ] php artisan module:make TestModule
Commit        : -
```

### Sub-task 1.2 — Semua Enum
```
Status        : [ ] TODO
Files Created : -
Enum Count    : - / 15
Commit        : -
```

### Sub-task 1.3 — Semua DTO
```
Status        : [ ] TODO
Files Created : -
DTO Count     : - / 19
Verified vs CLAUDE.md : [ ]
Commit        : -
```

### Sub-task 1.4 — Semua Interface
```
Status            : [ ] TODO
Files Created     : -
Interface Count   : - / 9
Commit            : -
CATATAN PENTING   : [diisi setelah selesai — interface contract yang di-expose]
```

### Sub-task 1.5 — Base Classes + TenantScope
```
Status        : [ ] TODO
Files Created : -
Tests Added   : -
Commit        : -
```

### Sub-task 1.6 — Docker Compose + Environment
```
Status              : [ ] TODO
Files Created       : -
Docker Up           : [ ]
Health /db          : [ ]
Health /redis       : [ ]
Health /wa-gateway  : [ ]
Commit              : -
```

### Sub-task 1.7 — Auth & Role System
```
Status        : [ ] TODO
Files Created : -
Tests Added   : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 1.8 — Tenant + Activation
```
Status        : [ ] TODO
Files Created : -
Tests Added   : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 1.9 — Plan & Feature Gating
```
Status        : [ ] TODO
Files Created : -
Plans Seeded  : [ ] Starter [ ] Growth [ ] Pro
Tests Pass    : - / -
Commit        : -
```

### Sub-task 1.10 — Filament Admin Panel Dasar
```
Status                  : [ ] TODO
Files Created           : -
Superadmin Panel        : [ ] /superadmin accessible
Tenant Panel            : [ ] /app accessible
Panel Isolation         : [ ] cross-access blocked
Commit                  : -
```

### Integration Checkpoint Phase 1
```
Status              : [ ] TODO
Tests               : - / - pass
Docker Up           : [ ]
Full Activation Flow: [ ]
Feature Gating      : [ ]
Tenant Isolation    : [ ]
Filament Panels     : [ ]
Git Tag             : v0.2-foundation-complete
Gate                : [ ] OPEN untuk Phase 2
```

---

## PHASE 2 — KNOWLEDGE & SETTINGS

### Sub-task 2.1 — Migration Knowledge Tables
```
Status         : [ ] TODO
Tables Created : -
Commit         : -
```

### Sub-task 2.2 — PackageResolver + PriceResolver
```
Status          : [ ] TODO
Files Created   : -
Methods Exposed :
  - PackageResolver::getActivePackages(tenantId)
  - PackageResolver::getPackageDetail(tenantId, slug)
  - PriceResolver::getActivePrice(packageId, date)
Tests Pass      : - / -
Commit          : -
```

### Sub-task 2.3 — KnowledgeService + AssetResolver
```
Status          : [ ] TODO
Files Created   : -
Methods Exposed : -
Tests Pass      : - / -
Commit          : -
```

### Sub-task 2.4 — Full-text Search + EmbeddingService
```
Status              : [ ] TODO
Files Created       : -
tsvector Works      : [ ]
pgvector Setup      : [ ] (Phase 3+, skip jika belum perlu)
Tests Pass          : - / -
Commit              : -
```

### Sub-task 2.5 — Tenant Settings + Policy + Business Hours
```
Status          : [ ] TODO
Files Created   : -
Methods Exposed :
  - TenantConfigResolver::resolve(tenantId, key, default)
  - BusinessHoursService::isOpen(tenantId, datetime)
  - TenantPolicyService::getPolicy(tenantId, policyKey)
Tests Pass      : - / -
Commit          : -
```

### Sub-task 2.6 — Filament Knowledge Panel
```
Status        : [ ] TODO
Files Created : -
Verified      : [ ] Tenant bisa input paket, harga, FAQ, pricelist
Commit        : -
```

### Sub-task 2.7 — Seed Data Wedding Realistis
```
Status       : [ ] TODO
Data Seeded  :
  Packages   : - (target: 3 paket)
  Prices     : - (target: 3 harga)
  FAQs       : - (target: 10 FAQ)
  Assets     : - (target: 1 pricelist PDF)
Commit       : -
```

### Integration Checkpoint Phase 2
```
Status              : [ ] TODO
Tests               : - / - pass
Knowledge Retrieval : [ ]
Tenant Isolation    : [ ]
Expired Price       : [ ] tidak muncul
Git Tag             : v0.3-knowledge-complete
Gate                : [ ] OPEN untuk Phase 3
```

---

## PHASE 3 — AI PIPELINE + LOGGING

### Sub-task 3.1 — LLM Adapter + MockLlmAdapter + JsonRepairGuard
```
Status                   : [ ] TODO
Files Created            : -
Interface Implemented    : LlmClientInterface
MockLlmAdapter           : [ ] dibuat untuk unit test
JsonRepairGuard          : [ ] dibuat
Tests Pass               : - / -
Commit                   : -
```

### Sub-task 3.2 — Token Usage Logger
```
Status        : [ ] TODO
Files Created : -
Table Created : llm_usage_logs
Tests Pass    : - / -
Commit        : -
```

### Sub-task 3.3 — InputSanitizerService (Security)
```
Status                 : [ ] TODO
Files Created          : -
Injection Patterns     : - / 10 pattern
Tests Pass             : - / -
Commit                 : -
```

### Sub-task 3.4 — IntentClassifierService
```
Status         : [ ] TODO
Files Created  : -
Interface Impl : IntentClassifierInterface
Prompt Version : - (dari PROMPTS.md)
Tests Pass     : - / -
Commit         : -
```

### Sub-task 3.5 — Intent Accuracy Test Suite
```
Status         : [ ] TODO
Test Cases     : - / 50
Accuracy Run 1 : - %
Accuracy Run 2 : - %
Accuracy Run 3 : - %
Average        : - %
Gate           : [ ] >= 85% sebelum lanjut 3.6
Baseline Saved : [ ] di PROGRESS.md
```

### Sub-task 3.6 — EntityExtractionService
```
Status         : [ ] TODO
Files Created  : -
Interface Impl : EntityExtractorInterface
Prompt Version : -
Tests Pass     : - / -
Commit         : -
```

### Sub-task 3.7 — EntityMatcherService
```
Status          : [ ] TODO
Files Created   : -
Methods Exposed : -
Tests Pass      : - / -
Commit          : -
```

### Sub-task 3.8 — Entity Accuracy Test Suite
```
Status         : [ ] TODO
Test Cases     : - / 30
Accuracy Run 1 : - %
Accuracy Run 2 : - %
Accuracy Run 3 : - %
Average        : - %
Gate           : [ ] >= 85% sebelum lanjut 3.9
```

### Sub-task 3.9 — DecisionEngineService
```
Status          : [ ] TODO
Files Created   : -
Interface Impl  : DecisionEngineInterface
Rule Groups     : - / 4
Tests Pass      : - / -
Commit          : -
```

### Sub-task 3.10 — BookingReadinessChecker
```
Status        : [ ] TODO
Files Created : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 3.11 — ValidatorChain (4 Validators)
```
Status     : [ ] TODO
Validators :
  Policy   : [ ]
  Grounding: [ ]
  Permission: [ ]
  Mode     : [ ]
Tests Pass : - / -
Commit     : -
```

### Sub-task 3.12 — Decision Accuracy Test Suite
```
Status         : [ ] TODO
Test Cases     : - / 40
Accuracy Run 1 : - %
Accuracy Run 2 : - %
Accuracy Run 3 : - %
Average        : - %
Gate           : [ ] >= 90% sebelum lanjut 3.13
```

### Sub-task 3.13 — ResponseComposerService
```
Status         : [ ] TODO
Files Created  : -
Interface Impl : ResponseComposerInterface
Prompt Version : -
Tests Pass     : - / -
Commit         : -
```

### Sub-task 3.14 — TurnPipelineService
```
Status          : [ ] TODO
Files Created   : -
Full Flow Works : [ ]
Tests Pass      : - / -
Commit          : -
```

### Sub-task 3.15 — Decision Trace Logging + Log Viewer
```
Status             : [ ] TODO
Files Created      : -
Table Created      : decision_traces
Log Viewer         : [ ] Filament page
All Fields Visible : [ ]
Tests Pass         : - / -
Commit             : -
```

### Integration Checkpoint Phase 3
```
Status                     : [ ] TODO
Intent Accuracy            : - % (target > 85%)
Entity Accuracy            : - % (target > 85%)
Decision Accuracy          : - % (target > 90%)
E2E Skenario Passed        : - / 20
Hallucination Found        : -
Injection Protected        : [ ]
Log Viewer Works           : [ ]
Degradation Check Baseline : [ ] saved
Git Tag                    : v0.4-pipeline-complete
Human Review               : [ ] done by external tester
Gate                       : [ ] OPEN untuk Phase 4
```

---

## PHASE 4 — WHATSAPP & CONVERSATION

### Sub-task 4.1 — WA Gateway Node.js (Baileys)
```
Status            : [ ] TODO
Files Created     : -
QR Generate       : [ ]
Session Persist   : [ ]
Inbound to Laravel: [ ]
Status Updates    : [ ]
Commit            : -
```

### Sub-task 4.2 — WA Account Management
```
Status        : [ ] TODO
Files Created : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 4.3 — Inbound Processor + Deduplication
```
Status             : [ ] TODO
Files Created      : -
Dedup via Redis    : [ ]
Duplicate Ignored  : [ ]
Tests Pass         : - / -
Commit             : -
```

### Sub-task 4.4 — Outbound Dispatcher + Retry
```
Status        : [ ] TODO
Files Created : -
Retry Works   : [ ]
Tests Pass    : - / -
Commit        : -
```

### Sub-task 4.5 — Conversation & Lead Management
```
Status        : [ ] TODO
Files Created : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 4.6 — Handoff & Notification System
```
Status        : [ ] TODO
Files Created : -
Tests Pass    : - / -
Commit        : -
```

### Sub-task 4.7 — Admin Takeover & Resume
```
Status         : [ ] TODO
Files Created  : -
Takeover Works : [ ]
Resume Works   : [ ]
Tests Pass     : - / -
Commit         : -
```

### Sub-task 4.8 — Filament Inbox + Context Panel
```
Status          : [ ] TODO
Files Created   : -
Inbox Works     : [ ]
Context Panel   : [ ]
Commit          : -
```

### Integration Checkpoint Phase 4
```
Status                 : [ ] TODO
Tests                  : - / - pass
WA Connect Real Phone  : [ ]
E2E from Real Phone    : - / 10
Log Visible Dashboard  : [ ]
Session Persist Restart: [ ]
Concurrent Message Test: [ ]
Human Review           : [ ]
Git Tag                : v0.5-whatsapp-complete
Gate                   : [ ] OPEN untuk Phase 5
```

---

## PHASE 5 — FEATURES & PRODUCTION

### Sub-task 5.1 — Pricelist Flow
```
Status     : [ ] TODO
Tests Pass : - / -
Commit     : -
```

### Sub-task 5.2 — Booking Flow
```
Status              : [ ] TODO
Concurrent Lock     : [ ]
Tests Pass          : - / -
Commit              : -
```

### Sub-task 5.3 — Invoice Lifecycle
```
Status     : [ ] TODO
Tests Pass : - / -
Commit     : -
```

### Sub-task 5.4 — Google Calendar Integration
```
Status            : [ ] TODO
Feature Gate      : [ ]
Concurrent Lock   : [ ]
Tests Pass        : - / -
Commit            : -
```

### Sub-task 5.5 — Memory & Follow-up System
```
Status     : [ ] TODO
Tests Pass : - / -
Commit     : -
```

### Sub-task 5.6 — Analytics Dashboard
```
Status       : [ ] TODO
Tenant Stats : [ ]
Superadmin   : [ ]
Token Usage  : [ ]
Commit       : -
```

### Sub-task 5.7 — Security Hardening
```
Status                  : [ ] TODO
Internal Secret         : [ ]
Rate Limiting           : [ ]
Tenant Isolation Test   : [ ]
File Upload Security    : [ ]
Log Sanitization        : [ ]
PII Handling            : [ ]
Tests Pass              : - / -
Commit                  : -
```

### Sub-task 5.8 — Production Docker & Deploy Pack
```
Status          : [ ] TODO
Prod Dockerfile : [ ]
Backup Script   : [ ]
Health Script   : [ ]
Deploy Script   : [ ]
Commit          : -
```

### Sub-task 5.9 — Production Smoke Test
```
Status                 : [ ] TODO
Deploy to Server       : [ ]
Health Checks          : [ ]
Backup Works           : [ ]
SSL Active             : [ ]
APP_DEBUG=false        : [ ]
30 Benchmark Scenarios : - / 30
Git Tag                : v1.0-production
```

### Integration Checkpoint Phase 5 (FINAL)
```
Status                   : [ ] TODO
All Tests                : - / - pass
Benchmark Score          : - / 30
Security Checklist       : - / 100%
Tenant Isolation Final   : [ ]
Production Deployed      : [ ]
Human Review Final       : [ ] 3+ external testers
Gate                     : [ ] PRODUCTION READY
```

---

## FILE REGISTRY

*Diupdate setiap sub-task selesai. Format: path | sub-task | exposed methods/classes*

```
Path | Sub-task | Exposed
-----|----------|--------

--- DOKUMENTASI (sudah ada sebelum development) ---
CLAUDE.md                                     | pre-dev | Project context, prinsip, DTO contract, edge cases
PROGRESS.md                                   | pre-dev | Tracking status semua sub-task
KANBAN.md                                     | pre-dev | Index file + referensi dokumen
KANBAN-PHASE-0.md                             | pre-dev | Prompt lengkap Phase 0
KANBAN-PHASE-1.md                             | pre-dev | Prompt lengkap Phase 1
PROMPTS.md                                    | pre-dev | Prompt library + anti-hallucination test + multi-language
BENCHMARK.md                                  | pre-dev | 30 skenario benchmark
DECISIONS.md                                  | pre-dev | Decision log + DoD + parking lot + API docs plan
GITFLOW.md                                    | pre-dev | Git strategy
SETUP.md                                      | pre-dev | Setup WSL2 + debugging LLM + seed data
RECOVERY.md                                   | pre-dev | 15 skenario error recovery
SECURITY.md                                   | pre-dev | Security guide + PII + prompt injection + production checklist
OPS.md                                        | pre-dev | Production ops + monitoring + scaling + backup
scripts/check_accuracy_regression.php         | pre-dev | checkAccuracy()
tests/conversation-data/intent-test-cases.json   | pre-dev | 50 test cases
tests/conversation-data/entity-test-cases.json   | pre-dev | 30 test cases
tests/conversation-data/decision-test-cases.json | pre-dev | 40 test cases
tests/conversation-data/e2e-scenarios.json       | pre-dev | 10 e2e scenarios

--- CODE (diisi saat development) ---
(belum ada — diisi saat development dimulai)
```

---

## KNOWN ISSUES & BLOCKERS

```
ID    | Sub-task | Issue | Status | Resolved By
------|----------|-------|--------|------------
(belum ada)
```

---

## TECHNICAL DEBT

```
ID    | Sub-task | Shortcut | Fix Deadline
------|----------|----------|-------------
(belum ada — lihat DECISIONS.md untuk tracking)
```

---

## CATATAN PENTING ANTAR SUB-TASK

*Diisi oleh Claude Code setiap ada keputusan penting yang perlu diketahui sub-task berikutnya*

```
[Phase 0 selesai]:
- Prompt versi berapa yang dipakai: -
- Accuracy yang dicapai: -
- Hal penting yang perlu diperhatikan Phase 1: -

[Phase 1 selesai]:
- DTO fields yang sudah frozen: -
- Interface contract yang exposed: -
- Hal penting Phase 2: -

[Phase 2 selesai]:
- Knowledge schema yang dipakai: -
- Hal penting Phase 3: -

[Phase 3 selesai]:
- Accuracy baseline yang harus dijaga: -
- Prompt versions yang dipakai: -
- Hal penting Phase 4: -

[Phase 4 selesai]:
- WA Gateway contract yang fixed: -
- Hal penting Phase 5: -
```
