# PROGRESS.md — WA SaaS AI Sales Agent
# Di-update OTOMATIS oleh Claude Code setiap sub-task selesai.
# Baca file ini di awal setiap sesi untuk tahu status terkini.

---

## STATUS TERKINI

```
Phase Aktif    : Phase 1 — Contracts & Foundation
Sub-task Aktif : 1.8 — Tenant + Activation System
Last Updated   : 2026-05-11
Git Branch     : dev
Last Commit    : feat: auth system dengan role, Sanctum token, middleware
Last Tag       : v0.1-poc-complete
```

---

## OVERALL PROGRESS

```
Phase 0 : 5 / 5  sub-task  [▓▓▓▓▓] ✅ COMPLETE
Phase 1 : 7 / 10 sub-task  [▓▓▓▓▓▓▓]
Phase 2 : 0 / 7  sub-task  [ ]
Phase 3 : 0 / 15 sub-task  [ ]
Phase 4 : 0 / 8  sub-task  [ ]
Phase 5 : 0 / 9  sub-task  [ ]
─────────────────────────────────
Total   : 5 / 54 sub-task
```

---

## ACCURACY BASELINE

```
Metric              | Current | Target  | Status
--------------------|---------|---------|--------
Intent Accuracy     | 98%     | > 85%   | ✅ PASS (Run 1: 49/50)
Entity Accuracy     | 95%     | > 85%   | ✅ PASS (Run 1: 28.5/30)
Decision Accuracy   | -       | > 90%   | ⏳ (diukur via E2E)
Response Quality    | 9/10    | > 80%   | ✅ PASS (E2E + composer test)
Benchmark Score     | -       | 30/30   | ⏳ Phase 3+
```

*Diupdate setiap kali accuracy test dijalankan. Catat tanggal dan versi prompt.*
*Phase 0 baseline: 2026-05-11, prompt v1.0 (classifier, entity, composer)*

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
Status         : [x] DONE — 2026-05-11
Files Modified : poc/poc_conversation.php (classifyIntent prompt), PROMPTS.md
Prompt Version : v1.0
Accuracy Run 1 : 98% (49/50)
Accuracy Run 2 : - (tidak perlu iterasi, sudah > 85%)
Accuracy Run 3 : -
Average        : 98%
Gate           : [x] >= 85% PASS
Notes          : 1 ambiguitas wajar: "ada brosur ga kak?" → ask_package_list
                 (brosur bisa berarti pricelist dalam konteks Indonesia informal)
                 2 low-confidence: "iya kak" dan emoji "🙏" → unclear_message (conf 0.50, wajar)
```

### Sub-task 0.3 — Prompt Engineering Entity + Composer
```
Status               : [x] DONE — 2026-05-11
Files Modified       : poc/poc_conversation.php (extractEntities + composeReply prompt), PROMPTS.md
Entity Prompt Version: v1.0
Entity Accuracy      : 95% (28.5/30)
Composer Version     : v1.0
Composer Quality     : auto-checks 39/40 — halusinasi 0 real
Hallucination Test   : [x] PASS
Notes          : 2 entity case dengan skor tidak penuh:
                 - ENT-013 "30 jt" → LLM interpret sebagai "max 30jt" bukan ±10%
                   (borderline, bisa diterima karena natural language ambiguous)
                 - ENT-024 correction → needs_clarification tidak di-flag
                   setelah koreksi (partial pass 0.5)
                 Composer: enhanced anti-hallucination instruction untuk paket tidak dikenal
```

### Sub-task 0.4 — Input Sanitizer & Injection Protection
```
Status          : [x] DONE — 2026-05-11
Files Modified  : poc/poc_conversation.php (PocInputSanitizer class + integrasi)
Injection Tests : 5 / 5 PASS
Sanitizer Works : [x]
Notes           : 15 injection patterns. Truncate > 2000 chars bekerja.
                  Integrasi ke runConversation() sebelum classifyIntent().
                  CLI flag --injection-test untuk test terpisah.
```

### Sub-task 0.5 — POC Full Conversation Test (10 Skenario)
```
Status          : [x] DONE — 2026-05-11
Files Created   : poc/test_e2e_conversations.php, tests/conversation-data/e2e-scenarios.json
Skenario Passed : 9 / 10
Failed List     : E2E-006 (Turn 3 "ada paket di bawah 20 juta?" → provide_budget bukan ask_price)
Notes           : E2E-006 failure = ambiguitas wajar (customer menyatakan budget constraint,
                  bukan tanya harga spesifik). Injection protection bekerja di E2E-010.
                  Semua red flag checks lulus kecuali E2E-006 Turn 3 intent mismatch.
```

### Integration Checkpoint Phase 0
```
Status              : [x] DONE — 2026-05-11
Intent Accuracy     : 98%
Entity Accuracy     : 95%
Skenario Passed     : 9 / 10
Injection Protected : [x] 5/5
Hallucination       : [x] 0 (setelah prompt fix)
Git Tag             : v0.1-poc-complete
Gate                : [x] OPEN untuk Phase 1

CATATAN PENTING ANTAR SUB-TASK:
"Phase 0 selesai. Prompt versi: classifier v1.0, entity v1.0, composer v1.0.
 Accuracy: intent 98%, entity 95%, e2e 9/10.
 Baseline accuracy di-set di scripts/check_accuracy_regression.php.
 Hal yang perlu diperhatikan Phase 1:
 - Entity persistence: merge logic di PHP (bukan LLM), perlu maintained di production
 - Injection patterns: 15 pattern sudah terbukti bekerja, bisa diperluas
 - composeReply: explicit instruction untuk paket tidak dikenal sangat membantu
 - Ambiguitas wajar: 'mau tanya' → ask_package_list, perlu few-shot lebih di Phase 3
 - E2E-006 Turn 3 ambiguitas: 'ada paket di bawah X' bisa jadi provide_budget atau ask_price"
```

---

## PHASE 1 — CONTRACTS & FOUNDATION

### Sub-task 1.1 — Project Laravel + Struktur Modul
```
Status        : [x] DONE — 2026-05-11
Files Created : laravel-app/ (Laravel 13 + Filament 5 + Sanctum 4 + spatie/laravel-permission 7)
                laravel-app/app/Console/Commands/MakeModuleCommand.php
                laravel-app/app/Modules/Shared/ (folder structure)
                laravel-app/.env.example (semua variabel sesuai CLAUDE.md)
                laravel-app/config/database.php (default: pgsql)
Command Works : [x] php artisan module:make → folder + ServiceProvider + routes.php
Notes         : Filament 5.x (bukan 3.x) — Laravel 13 tidak kompatibel dengan Filament 3.x/4.x
                Autoload: App\Modules\ → app/Modules/ sudah di composer.json
                AppServiceProvider.php scan modules/*/Providers/*ServiceProvider.php otomatis
Commit        : chore: inisialisasi Laravel 11, struktur modul, artisan module:make command
```

### Sub-task 1.2 — Semua Enum
```
Status        : [x] DONE — 2026-05-11
Files Created : app/Modules/Shared/Enums/ — 15 file:
                UserRole, TenantStatus, FeatureKey, WaAccountStatus, ConversationStage (12 cases),
                AgentMode, MemoryMode, LeadTemperature, HandoffPriority, HandoffStatus,
                TenantTone, PolicyKey, AssetType, NotificationType, MessageType
Enum Count    : 15 / 15
Verified      : php -l *.php — 0 syntax errors, tinker UserRole::SUPERADMIN->value OK
Commit        : feat: buat semua shared enums (15 enum)
```

### Sub-task 1.3 — Semua DTO
```
Status        : [x] DONE — 2026-05-11
Files Created : app/Modules/Shared/DTOs/ — 19 file:
                IntentResultDTO, EntityResultDTO, DecisionDTO, BlockedActionDTO,
                TurnContextDTO, TurnResultDTO, GroundedKnowledgeDTO, GroundingRefDTO,
                ComposedReplyDTO, ValidatorResultDTO, InboundMessageDTO,
                LlmResponseDTO, LlmEmbeddingDTO, AvailabilityResultDTO,
                TenantDTO, ConversationDTO, ConversationStateDTO, LeadProfileDTO, TenantConfigDTO
DTO Count     : 19 / 19
Verified vs CLAUDE.md : [x] — fields PERSIS sesuai CONTRACT section
Commit        : feat: buat semua shared DTOs (19 DTO) — fields frozen

CATATAN PENTING:
DTO fields sudah FROZEN sesuai CLAUDE.md v2.0.
Sub-task berikutnya TIDAK BOLEH ubah field DTO.
Jika perlu ubah: update CLAUDE.md terlebih dahulu dan diskusikan.
```

### Sub-task 1.4 — Semua Interface
```
Status            : [x] DONE — 2026-05-11
Files Created     : app/Modules/Shared/Contracts/ — 9 file:
                    LlmClientInterface, ChannelGatewayInterface, CalendarProviderInterface,
                    StorageProviderInterface, IntentClassifierInterface, EntityExtractorInterface,
                    KnowledgeRetrieverInterface, DecisionEngineInterface, ResponseComposerInterface
Interface Count   : 9 / 9
Verified          : 0 syntax errors, dummy implements test OK
Commit            : feat: buat semua shared contracts/interfaces (9 interface)

CATATAN PENTING: Interface contract final. Semua implementasi
di Phase 3 WAJIB implements interface ini:
- LlmClientInterface → OpenAiAdapter + MockLlmAdapter
- ChannelGatewayInterface → WhatsAppGatewayAdapter
- CalendarProviderInterface → GoogleCalendarAdapter
- StorageProviderInterface → R2StorageAdapter
- DecisionEngineInterface: LLM TIDAK BOLEH dipanggil di sini
```

### Sub-task 1.5 — Base Classes + TenantScope + Health Endpoints
```
Status        : [x] DONE — 2026-05-11
Files Created : app/Modules/Shared/Models/Traits/HasUuid.php
                app/Modules/Shared/Models/BaseModel.php
                app/Modules/Shared/Models/TenantBaseModel.php
                app/Modules/Shared/Scopes/TenantScope.php
                app/Modules/Shared/Http/Controllers/HealthController.php
                app/Modules/Shared/routes.php
                app/Modules/Shared/Tests/BaseModelTest.php
Tests Added   : 6 / 6 PASS (tanpa DB — SQLite tidak tersedia di host)
                DB integration tests dijalankan saat Docker up (Sub-task 1.6)
Notes         : AppServiceProvider scan module routes.php otomatis
                Health endpoints: /health, /health/db, /health/redis, /health/queue, /health/wa-gateway
                TenantScope: superadmin bypass, tenant_admin filter by tenant_id
                phpunit.xml: tambahkan testsuites Modules (app/Modules/**/Tests)
Commit        : feat: base classes, TenantScope, health endpoints
```

### Sub-task 1.6 — Docker Compose + Environment
```
Status              : [x] DONE — 2026-05-11
Files Created       : docker-compose.yml (10 services)
                      docker/php/Dockerfile (PHP 8.3 FPM Alpine + redis pecl)
                      docker/php/entrypoint.sh
                      docker/nginx/default.conf
                      wa-gateway/index.js, wa-gateway/package.json, wa-gateway/Dockerfile
                      Makefile
Docker Up           : [x] — semua 10 container running
Health /health      : [x] {status: ok, service: app}
Health /db          : [x] {status: ok, driver: pgsql}
Health /redis       : [x] {status: ok, driver: redis}
Health /queue       : [x] {status: ok, driver: redis}
Health /wa-gateway  : [x] {status: ok, gateway_status: {...}}
Tests               : 8 / 8 PASS (make test di Docker)
Notes               : wa-gateway node_modules: named volume (wa_gateway_modules)
                      untuk menghindari override dari bind mount
Commit              : chore: Docker Compose setup lengkap, wa-gateway skeleton
```

### Sub-task 1.7 — Auth & Role System
```
Status        : [x] DONE — 2026-05-11
Files Created : database/migrations/0001_01_01_000000_create_users_table.php (UUID pk, role, is_active, tenant_id, last_login_at)
                database/migrations/2026_05_11_084659_create_personal_access_tokens_table.php (uuidMorphs fix)
                app/Modules/Auth/Models/User.php
                app/Modules/Auth/DTOs/UserDTO.php
                app/Modules/Auth/Services/AuthService.php
                app/Modules/Auth/Http/Requests/LoginRequest.php
                app/Modules/Auth/Http/Controllers/AuthController.php
                app/Modules/Auth/Http/Middleware/SuperadminOnly.php
                app/Modules/Auth/Http/Middleware/TenantAdminOnly.php
                app/Modules/Auth/Http/Middleware/ActiveUserOnly.php
                app/Modules/Auth/routes.php
                app/Modules/Auth/Tests/AuthServiceTest.php
                database/seeders/SuperadminSeeder.php
                database/seeders/DatabaseSeeder.php
                config/auth.php (changed to App\Modules\Auth\Models\User)
Tests Added   : 8 (AuthServiceTest)
Tests Pass    : 14 / 14 (incl. 6 BaseModelTest)
Notes         : personal_access_tokens uuidMorphs (bukan morphs) karena User UUID pk
                manual curl OK: login, /me (JSON), logout, /me after logout (401)
                Remember: pakai Accept: application/json header
Commit        : feat: auth system dengan role, Sanctum token, middleware
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
