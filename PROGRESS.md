# PROGRESS.md — WA SaaS AI Sales Agent
# Di-update OTOMATIS oleh Claude Code setiap sub-task selesai.
# Baca file ini di awal setiap sesi untuk tahu status terkini.

---

## STATUS TERKINI

```
Phase Aktif    : Phase 3 — AI Pipeline & Logging
Sub-task Aktif : 3.5 — Conversation + ConversationState + Lead Models + Migrations
Last Updated   : 2026-05-14
Git Branch     : dev
Last Commit    : feat: EntityExtractionService — wedding entity extraction, normalization, entity merge
Last Tag       : v0.3-knowledge-complete
```

---

## OVERALL PROGRESS

```
Phase 0 : 5 / 5  sub-task  [▓▓▓▓▓] ✅ COMPLETE
Phase 1 : 10 / 10 sub-task  [▓▓▓▓▓▓▓▓▓▓] ✅ COMPLETE ✅ CHECKPOINT PASSED
Phase 2 : 7 / 7  sub-task  [▓▓▓▓▓▓▓] ✅ COMPLETE ✅ CHECKPOINT PASSED
Phase 3 : 4 / 15 sub-task  [▓▓▓▓           ]
Phase 4 : 0 / 8  sub-task  [ ]
Phase 5 : 0 / 9  sub-task  [ ]
─────────────────────────────────
Total   : 12 / 54 sub-task
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
Status        : [x] DONE — 2026-05-11
Files Created : database/migrations/2026_05_11_094006_create_tenants_table.php
                database/migrations/2026_05_11_094008_create_tenant_users_table.php
                database/migrations/2026_05_11_094009_create_activation_tokens_table.php
                app/Modules/Tenancy/Models/Tenant.php
                app/Modules/Tenancy/Models/TenantUser.php
                app/Modules/Tenancy/Models/ActivationToken.php
                app/Modules/Tenancy/Services/TenantService.php
                app/Modules/Tenancy/Services/ActivationService.php
                app/Modules/Tenancy/Mail/ActivationEmail.php
                app/Modules/Tenancy/Http/Controllers/SuperadminTenantController.php
                app/Modules/Tenancy/Http/Controllers/ActivationController.php
                app/Modules/Tenancy/Http/Requests/CreateTenantRequest.php
                app/Modules/Tenancy/Http/Requests/ActivateRequest.php
                app/Modules/Tenancy/routes.php
                app/Modules/Tenancy/TenancyServiceProvider.php
                app/Modules/Tenancy/Tests/TenancyServiceTest.php
                app/Providers/AppServiceProvider.php (fix: scan root-level module providers)
Tests Added   : 7 (TenancyServiceTest)
Tests Pass    : 23 / 23 (full suite, 0 regresi)
Notes         : Token hashing: SHA-256, raw token tidak disimpan (transient property)
                Migration order fix: tenant_users(094008) dan activation_tokens(094009) setelah tenants(094006)
                TenantService::create() di dalam DB::transaction — atomic
                ActivationEmail: inline HTML, link ke APP_URL/activate/{rawToken}
                AppServiceProvider diperluas: scan root-level *ServiceProvider.php juga
Commit        : feat: tenant management dan activation system
```

### Sub-task 1.9 — Plan & Feature Gating
```
Status        : [x] DONE — 2026-05-11
Files Created : database/migrations/2026_05_11_100000_create_plans_table.php
                database/migrations/2026_05_11_100001_create_plan_features_table.php
                database/migrations/2026_05_11_100002_create_tenant_subscriptions_table.php
                app/Modules/Plans/Models/Plan.php
                app/Modules/Plans/Models/PlanFeature.php
                app/Modules/Plans/Models/TenantSubscription.php
                app/Modules/Plans/Services/FeatureGateService.php
                app/Modules/Plans/Http/Middleware/CheckFeatureEnabled.php
                app/Modules/Plans/Http/Controllers/PlanController.php
                app/Modules/Plans/PlansServiceProvider.php
                app/Modules/Plans/routes.php
                app/Modules/Plans/Tests/FeatureGateServiceTest.php
                database/seeders/PlanSeeder.php
Plans Seeded  : [x] Starter [x] Growth [x] Pro
Tests Added   : 13 (FeatureGateServiceTest)
Tests Pass    : 36 / 36 (full suite, 0 regresi)
Notes         : FeatureGateService cache 5 menit di Redis per tenant_id
                Cache invalidate otomatis saat assign-plan
                POST /api/superadmin/tenants/{id}/assign-plan [superadmin only]
                CheckFeatureEnabled middleware: 403 jika feature disabled
Commit        : feat: plan system, feature gating, FeatureGateService
```

### Sub-task 1.10 — Filament Admin Panel Dasar
```
Status                  : [x] DONE — 2026-05-11
Files Created           : app/Providers/Filament/SuperadminPanelProvider.php
                          app/Providers/Filament/TenantPanelProvider.php
                          app/Filament/Superadmin/Resources/TenantResource.php
                          app/Filament/Superadmin/Resources/TenantResource/Pages/ListTenants.php
                          app/Filament/Superadmin/Resources/TenantResource/Pages/CreateTenant.php
                          app/Filament/Superadmin/Resources/PlanResource.php
                          app/Filament/Superadmin/Resources/PlanResource/Pages/ListPlans.php
                          app/Filament/Superadmin/Widgets/TenantStatsWidget.php
                          app/Filament/Tenant/Pages/Dashboard.php
                          app/Modules/Filament/Tests/FilamentPanelTest.php
                          database/factories/Modules/Auth/Models/UserFactory.php
Superadmin Panel        : [x] /superadmin accessible (login page + canAccessPanel)
Tenant Panel            : [x] /app accessible
Panel Isolation         : [x] cross-access blocked (403 Forbidden)
Tests Added             : 10 (FilamentPanelTest)
Tests Pass              : 46 / 46 (full suite, 0 regresi)
Notes                   : Filament 5.x — form() uses Schema tidak Form
                          Actions pindah ke Filament\Actions\* (bukan Tables\Actions\*)
                          User model butuh Illuminate\Foundation\Auth\Access\Authorizable (bukan Auth\Access)
                          SuperadminPanel: TenantResource, PlanResource, TenantStatsWidget
                          TenantPanel: Custom Dashboard dengan welcome message
                          canAccessPanel: superadmin → /superadmin, tenant_admin → /app
Commit                  : feat: Filament superadmin dan tenant panel dengan resources dasar
```

### Integration Checkpoint Phase 1
```
Status              : [x] DONE — 2026-05-11
Tests               : 66 / 66 pass (109 assertions, 0 regresi)
Docker Up           : [x] 10 containers running (app, nginx, postgres, redis, queue, scheduler, horizon, wa-gateway, mailpit, pgadmin)
Full Activation Flow: [x] Superadmin create → email Mailpit → activate token → tenant login OK
Feature Gating      : [x] Starter: calendar disabled, lead_limit=100 | Growth: calendar enabled
Tenant Isolation    : [x] Tenant A cannot access superadmin API (403 Forbidden)
Filament Panels     : [x] /superadmin (302 redirect unauthenticated) | /app (302 redirect unauthenticated)
WA Gateway Contract : [x] POST /webhook/inbound (Laravel) → format accepted, X-Internal-Secret enforced
                         [x] POST /dispatch (WA Gateway) → {success: true, provider_message_id: uuid}
                         [x] GET /status/:id (WA Gateway) → {status, phone, connected_at}
                         [x] Unauthorized request → 403 (both sides)
MockLlmAdapter      : [x] 10 unit tests pass, no real OpenAI call
Health Checks       : [x] /health /health/db /health/redis /health/queue /health/wa-gateway
Git Tag             : v0.2-foundation-complete
Gate                : [x] OPEN untuk Phase 2

Files Added (Checkpoint):
  wa-gateway/index.js                                           | 1.10/CP | POST /dispatch, GET /status/:id (PRINSIP 13)
  app/Modules/Shared/Http/Controllers/WebhookController.php     | CP      | inbound() — POST /webhook/inbound stub
  app/Modules/Shared/routes.php                                 | CP      | added POST /webhook/inbound route
  tests/Feature/Contracts/WaGatewayContractTest.php             | CP      | 10 contract tests (PRINSIP 13)
  app/Modules/AgentCore/LLM/Adapters/MockLlmAdapter.php         | CP      | MockLlmAdapter implements LlmClientInterface (PRINSIP 9)
  app/Modules/AgentCore/Tests/MockLlmAdapterTest.php            | CP      | 10 unit tests, no real API
```

---

## PHASE 2 — KNOWLEDGE & SETTINGS

### Sub-task 2.1 — Migration Knowledge Tables
```
Status         : [x] DONE — 2026-05-14
Tables Created : packages, package_prices, faqs, knowledge_items,
                 assets, tenant_settings, tenant_policies
Migration Count: 7
Files Created  : database/migrations/2026_05_12_000001_create_packages_table.php
                 database/migrations/2026_05_12_000002_create_package_prices_table.php
                 database/migrations/2026_05_12_000003_create_faqs_table.php
                 database/migrations/2026_05_12_000004_create_knowledge_items_table.php
                 database/migrations/2026_05_12_000005_create_assets_table.php
                 database/migrations/2026_05_12_000006_create_tenant_settings_table.php
                 database/migrations/2026_05_12_000007_create_tenant_policies_table.php
                 database/migrations/2026_05_12_000008_create_tsvector_triggers.php
Notes          : tsvector columns (search_vector) added via raw SQL for faqs dan knowledge_items.
                 GIN indexes for full-text search. Cascade delete verified.
                 tenant_settings adalah 1-to-1 (UNIQUE tenant_id FK).
Commit         : feat: migration knowledge & settings tables (7 tabel)
```

### Sub-task 2.2 — Models + PackageResolver + PriceResolver
```
Status          : [x] DONE — 2026-05-14
Files Created   : app/Modules/Knowledge/KnowledgeServiceProvider.php (generated)
                  app/Modules/Knowledge/routes.php (generated)
                  app/Modules/Knowledge/Models/Package.php
                  app/Modules/Knowledge/Models/PackagePrice.php
                  app/Modules/Knowledge/Models/Faq.php
                  app/Modules/Knowledge/Models/KnowledgeItem.php
                  app/Modules/Knowledge/Models/Asset.php
                  app/Modules/Knowledge/Services/PackageResolver.php
                  app/Modules/Knowledge/Services/PriceResolver.php
                  app/Modules/Knowledge/Tests/PackageResolverTest.php
Migration Fixes : 2026_05_12_000003_create_faqs_table.php — tsvector conditioned on pgsql driver
                  2026_05_12_000004_create_knowledge_items_table.php — tsvector conditioned + jsonb→json
                  2026_05_12_000006_create_tenant_settings_table.php — jsonb→json for SQLite compat
Other Created   : .env.testing (override CACHE_STORE ke array untuk tests)
Methods Exposed :
  - PackageResolver::getActivePackages(tenantId): Collection [cached 10m Redis]
  - PackageResolver::getPackageDetail(tenantId, slug): ?Package
  - PackageResolver::matchByName(tenantId, rawName): ?Package [ILIKE fallback, cached 5m]
  - PackageResolver::invalidateCache(tenantId): void
  - PriceResolver::getActivePrice(packageId, date): ?PackagePrice [cached 30m]
  - PriceResolver::getLowestCurrentPrice(tenantId): ?PackagePrice
  - PriceResolver::getPriceRange(tenantId): array [min, max, date]
Tests Pass      : 12 / 12 PASS (PackageResolverTest)
                  78 / 78 total suite PASS (naik dari 66 di Phase 1)
Notes           : Tests pakai config(['cache.default' => 'array']) di setUp()
                  untuk menghindari Redis serialization issues dgn Eloquent Collection.
                  Migration tsvector conditioned on pgsql untuk SQLite compat di tests.
Commit          : feat: Knowledge models, PackageResolver, PriceResolver
```

### Sub-task 2.3 — KnowledgeService + AssetResolver
```
Status          : [x] DONE — 2026-05-14
Files Created   : app/Modules/Knowledge/Services/KnowledgeService.php
                  app/Modules/Knowledge/Services/AssetResolver.php
                  app/Modules/Knowledge/Services/KnowledgeRetrieverService.php
                  app/Modules/Knowledge/KnowledgeServiceProvider.php (updated — bind interface)
                  app/Modules/Knowledge/Tests/KnowledgeServiceTest.php
Methods Exposed :
  - KnowledgeService::getFaqsByCategory(tenantId, category): Collection
  - KnowledgeService::searchFaqs(tenantId, query, limit): Collection [tsvector + LIKE fallback]
  - KnowledgeService::searchKnowledgeItems(tenantId, query, limit): Collection [tsvector + LIKE fallback]
  - KnowledgeService::getFaqAsGroundingRefs(faqs): GroundingRefDTO[]
  - AssetResolver::getActivePricelist(tenantId): ?Asset
  - AssetResolver::getAssetsByType(tenantId, type): Collection
  - AssetResolver::getPricelistUrl(tenantId): ?string
  - KnowledgeRetrieverService::retrieve(intent, entities, tenantId): GroundedKnowledgeDTO [STUB]
  - KnowledgeRetrieverInterface → KnowledgeRetrieverService (bound in container)
Tests Pass      : 14 / 14 PASS (KnowledgeServiceTest)
                  92 / 92 total suite PASS (naik dari 78)
Notes           : KnowledgeRetrieverService adalah STUB untuk Phase 2.
                  Full implementation dengan tsvector ranking dan pgvector ada di Phase 3.
                  tsvector path guarded with DB::getDriverName() === 'pgsql' for SQLite compat.
                  Semua unit test Phase 2 pakai STUB, tidak call real DB for vector search.
Commit          : feat: KnowledgeService, AssetResolver, KnowledgeRetrieverService stub
```

### Sub-task 2.4 — Full-text Search Setup (tsvector)
```
Status              : [x] DONE — 2026-05-14
Files Created       :
  - database/migrations/2026_05_12_000008_create_tsvector_triggers.php
  - app/Modules/Knowledge/Tests/TsvectorSearchTest.php
  - app/Console/Commands/RebuildSearchVectorsCommand.php
tsvector Works      : [x] — DB function + trigger auto-fill search_vector on INSERT/UPDATE
pgvector Setup      : [ ] (Phase 3+)
Tests Pass          : 9 / 9 (TsvectorSearchTest) — 101 total pass
Notes               : tsvector pakai 'simple' config (bukan 'indonesian') karena
                      PostgreSQL Alpine tidak include Indonesian stemming dict.
                      Trigger tests berjalan di PostgreSQL (docker), skip di SQLite.
Commit              : feat: tsvector full-text search setup, trigger, RebuildSearchVectorsCommand
```

### Sub-task 2.5 — Tenant Settings + Policy + Business Hours
```
Status          : [x] DONE — 2026-05-14
Files Created   :
  app/Modules/TenantConfig/Models/TenantSetting.php
  app/Modules/TenantConfig/Models/TenantPolicy.php
  app/Modules/TenantConfig/Support/PolicyDefaults.php
  app/Modules/TenantConfig/Services/TenantConfigResolver.php
  app/Modules/TenantConfig/Services/BusinessHoursService.php
  app/Modules/TenantConfig/Services/TenantPolicyService.php
  app/Modules/TenantConfig/TenantConfigServiceProvider.php
  app/Modules/TenantConfig/Tests/TenantConfigTest.php
  app/Modules/Tenancy/Models/Tenant.php (updated: settings, policies relations)
Methods Exposed :
  - TenantConfigResolver::resolve(tenantId): TenantConfigDTO
  - TenantConfigResolver::get(tenantId, key, default): mixed
  - TenantConfigResolver::invalidateCache(tenantId): void
  - BusinessHoursService::isOpen(tenantId, datetime): bool
  - BusinessHoursService::getNextOpenTime(tenantId, from): Carbon
  - BusinessHoursService::getAfterHoursBehavior(tenantId): string
  - TenantPolicyService::getPolicy(tenantId, policyKey): string
  - TenantPolicyService::getPolicies(tenantId): array
  - TenantPolicyService::setPolicy(tenantId, policyKey, value): void
Tests Pass      : 17 / 17 (118 total suite)
Notes           : TenantConfigDTO cached as array (not object) to avoid
                  PHP incomplete class on Redis deserialization.
                  TenantSetting extends BaseModel (not TenantBaseModel) — 1-to-1 with tenants.
Commit          : feat: TenantConfig, BusinessHoursService, TenantPolicyService, PolicyDefaults
```

### Sub-task 2.6 — Filament Knowledge Panel
```
Status        : [x] DONE — 2026-05-14
Files Created :
  app/Filament/Tenant/Resources/PackageResource.php
  app/Filament/Tenant/Resources/PackageResource/Pages/ListPackages.php
  app/Filament/Tenant/Resources/PackageResource/Pages/CreatePackage.php
  app/Filament/Tenant/Resources/PackageResource/Pages/EditPackage.php
  app/Filament/Tenant/Resources/PackageResource/RelationManagers/PricesRelationManager.php
  app/Filament/Tenant/Resources/FaqResource.php
  app/Filament/Tenant/Resources/FaqResource/Pages/ListFaqs.php
  app/Filament/Tenant/Resources/FaqResource/Pages/CreateFaq.php
  app/Filament/Tenant/Resources/FaqResource/Pages/EditFaq.php
  app/Filament/Tenant/Resources/KnowledgeItemResource.php
  app/Filament/Tenant/Resources/KnowledgeItemResource/Pages/ListKnowledgeItems.php
  app/Filament/Tenant/Resources/KnowledgeItemResource/Pages/CreateKnowledgeItem.php
  app/Filament/Tenant/Resources/KnowledgeItemResource/Pages/EditKnowledgeItem.php
  app/Filament/Tenant/Resources/AssetResource.php
  app/Filament/Tenant/Resources/AssetResource/Pages/ListAssets.php
  app/Filament/Tenant/Resources/AssetResource/Pages/CreateAsset.php
  app/Filament/Tenant/Resources/AssetResource/Pages/EditAsset.php
  app/Filament/Tenant/Pages/TenantSettings.php
  app/Filament/Tenant/Pages/PolicySettings.php
  resources/views/filament/tenant/pages/tenant-settings.blade.php
  resources/views/filament/tenant/pages/policy-settings.blade.php
  tests/Feature/Filament/FilamentKnowledgePanelTest.php
Verified      : [x] Tenant bisa input paket, harga, FAQ, pricelist, settings, policies
Tenant Isolation: [x] getEloquentQuery() scoped ke tenant_id di semua resources
                  [x] CreateXxx->mutateFormDataBeforeCreate inject tenant_id dari auth user
                  [x] Other tenant package edit → 404
Tests Added   : 10 (FilamentKnowledgePanelTest)
Tests Pass    : 128 / 128 total suite PASS
Navigation    : Group "Pengetahuan": Paket, FAQ, Pengetahuan, Aset
                Group "Pengaturan": Pengaturan Bisnis, Kebijakan
Notes         : Filament 5.x — $navigationGroup property type conflict (UnitEnum|string|null)
                → fixed via getNavigationGroup() method override.
                Filament 5.x — Page::$view is non-static → fixed via getView() method.
                Page::$navigationIcon type differs (BackedEnum|string|null) in Page vs Resource
                → fixed via getNavigationIcon() method.
                Blade view uses simple <form wire:submit.prevent="save"> (no filament-panels::form.actions).
                Save triggered via header Action::make('save')->action('save').
Commit        : feat: Filament tenant panel — package, faq, knowledge, asset, settings resources
```

### Sub-task 2.7 — Seed Data Wedding Realistis
```
Status       : [x] DONE — 2026-05-14
Files Created:
  database/seeders/WeddingDemoSeeder.php
  database/seeders/DatabaseSeeder.php (updated: add WeddingDemoSeeder)
Data Seeded  :
  Tenant     : Capture Moment Photography (slug: capture-moment-photography)
  User       : demo@capturemoment.id / Demo123! (TENANT_ADMIN)
  Subscription: Growth plan, 1 tahun aktif
  Settings   : timezone Asia/Jakarta, 09:00-20:00, Senin-Sabtu, semi_formal
  Packages   : 3 paket (Intimate, Standard, Premium)
  Prices     : 8 harga total (2 Intimate, 3 Standard, 3 Premium, incl. peak season)
  FAQs       : 10 FAQ realistis (harga, paket, proses, booking, pembayaran, lokasi)
  KnowledgeItems: 3 item (terms, process, policy)
Idempotent   : [x] updateOrCreate — bisa dijalankan berulang
search_vector: [x] auto-filled via DB trigger (tidak null setelah insert)
QA Passed    :
  PackageResolver::getActivePackages  → 3 paket
  PriceResolver::getLowestCurrentPrice → Rp 8.000.000
  KnowledgeService::searchFaqs('harga') → 2 FAQ
  TenantConfigResolver::resolve → timezone Asia/Jakarta, tone semi_formal
Tests Pass   : 128 / 128 PASS (0 regresi)
Commit       : feat: WeddingDemoSeeder — 1 tenant demo, 3 paket, 10 FAQ, data realistis
```

### Integration Checkpoint Phase 2
```
Status              : [x] DONE — 2026-05-14
Tests               : 128 / 128 pass (217 assertions, 0 regresi)
Docker Containers   : [x] 10 containers running

Knowledge Retrieval:
  [x] PackageResolver::getActivePackages → 3 paket demo
  [x] PriceResolver::getActivePrice peak season (Jul) → Rp 20.000.000
  [x] PriceResolver::getActivePrice expired → null (tidak muncul)
  [x] KnowledgeService::searchFaqs('harga') → 2 FAQ
  [x] KnowledgeRetrieverService::retrieve → GroundedKnowledgeDTO valid

tsvector Search:
  [x] Insert FAQ baru → search_vector auto-filled via trigger
  [x] Search 'booking' → 2 FAQ muncul
  [x] Search nonexistent word → Collection kosong (tidak error)

Tenant Settings:
  [x] TenantConfigDTO timezone Asia/Jakarta, tone semi_formal, hours 09:00
  [x] BusinessHoursService::isOpen Mon 10:00 WIB → true
  [x] BusinessHoursService::isOpen Mon 22:00 WIB → false
  [x] TenantPolicyService INVOICE_MAX_RESEND default → '3'

Tenant Isolation:
  [x] Package tenant B tidak muncul di PackageResolver tenant A
  [x] FAQ tenant B tidak muncul di KnowledgeService tenant A

Full Pipeline:
  [x] retrieve search_method = 'tsvector'
  [x] grounding_refs count = 7 (> 0)
  [x] structured_data['price_range'] ada
  [x] structured_data['matched_package'] ada
  [x] KnowledgeRetrieverInterface::class bound → KnowledgeRetrieverService

Expired Price:
  [x] Price valid_until = yesterday → getActivePrice(today) null
  [x] Harga expired tidak muncul di hasil

WeddingDemoSeeder:
  [x] Idempotent: bisa dijalankan berulang
  [x] search_vector auto-filled setelah seed

Git Tag             : v0.3-knowledge-complete
Gate                : [x] OPEN untuk Phase 3

CATATAN PENTING ANTAR SUB-TASK Phase 2:
"Phase 2 selesai. Knowledge schema: packages, package_prices, faqs,
 knowledge_items, assets, tenant_settings, tenant_policies (7 tabel).

 KnowledgeRetrieverService adalah STUB — hanya tsvector + structured data.
 Full implementation (pgvector ranking, hybrid search) ada di Phase 3.

 Demo tenant:
   slug: capture-moment-photography
   login: demo@capturemoment.id / Demo123!
   3 paket: intimate/standard/premium dengan harga weekday/weekend/peak
   10 FAQ realistis + 3 knowledge items

 Hal penting untuk Phase 3:
 - LLM adapter: OpenAiAdapter implements LlmClientInterface
 - MockLlmAdapter sudah ada di Phase 1 Checkpoint
 - IntentClassifierService, EntityExtractionService perlu dibuat
 - DecisionEngineService: PHP ONLY, no LLM (PRINSIP 1)
 - TurnPipelineService: urutan pipeline wajib sesuai PRINSIP 2
 - Cache Redis conflict: jika multiple calls ke PackageResolver dalam
   1 tinker session, clear cache dulu (serialize issue Eloquent Collection)
 - tsvector pakai 'simple' config (bukan 'indonesian')
 - Filament 5.x notes: Schema bukan Form, getNavigationGroup() method, getView() method"
```

---

## PHASE 3 — AI PIPELINE + LOGGING

### Sub-task 3.1 — OpenAiAdapter + JsonRepairGuard + TokenUsageLogger (KANBAN 3.1)
```
Status                   : [x] DONE — 2026-05-14
Files Created            : config/llm.php
                           app/Modules/AgentCore/LLM/Adapters/OpenAiAdapter.php
                           app/Modules/AgentCore/LLM/Exceptions/LlmException.php
                           app/Modules/AgentCore/LLM/Exceptions/LlmJsonParseException.php
                           app/Modules/AgentCore/LLM/JsonRepairGuard.php
                           app/Modules/AgentCore/LLM/Services/TokenUsageLogger.php
                           app/Modules/AgentCore/Providers/AgentCoreServiceProvider.php
                           app/Modules/AgentCore/Tests/OpenAiAdapterTest.php
Methods Exposed          : OpenAiAdapter::complete(), completeJson(), embed()
                           JsonRepairGuard::repair(), isValidJson()
                           TokenUsageLogger::log(), getMonthlyUsage()
Interface Implemented    : LlmClientInterface (OpenAiAdapter)
LLM_PROVIDER binding     : 'mock' → MockLlmAdapter | 'openai' → OpenAiAdapter
.env.testing             : LLM_PROVIDER=mock, LOG_CHANNEL=null
Tests Pass               : 17 / 17 (OpenAiAdapterTest)
Commit                   : feat: OpenAiAdapter, JsonRepairGuard, TokenUsageLogger — LLM infrastructure

CATATAN PENTING:
- OpenAiAdapter: retry max 2x, exponential backoff 1s/2s pada timeout/rate-limit
- JsonRepairGuard: 3-step repair (plain → strip markdown → substring extraction)
- TokenUsageLogger: Redis dengan TTL 90 hari, per-purpose breakdown
- AgentCoreServiceProvider auto-discovered oleh AppServiceProvider (glob pattern)
- .env.testing: LLM_PROVIDER=mock → semua unit test pakai MockLlmAdapter (PRINSIP 9)
```

### Sub-task 3.2 — InputSanitizerService (KANBAN 3.2)
```
Status        : [x] DONE — 2026-05-14
Files Created : app/Modules/Shared/DTOs/SanitizedInputDTO.php
                app/Modules/AgentCore/Security/Services/InputSanitizerService.php
                app/Modules/AgentCore/Tests/InputSanitizerServiceTest.php
Methods       : sanitize(input, tenantId, conversationId): SanitizedInputDTO
                maskPhone(phone): string
Patterns      : 15 injection patterns (CLAUDE.md PRINSIP 12)
Tests Pass    : 14 / 14
Commit        : feat: InputSanitizerService — 15 injection patterns, PII masking, SanitizedInputDTO
```

### Sub-task 3.3 — InputSanitizerService (Security)
```
Status                 : [ ] TODO
Files Created          : -
Injection Patterns     : - / 10 pattern
Tests Pass             : - / -
Commit                 : -
```

### Sub-task 3.4 — IntentClassifierService (KANBAN 3.3)
```
Status         : [x] DONE — 2026-05-14
Files Created  : app/Modules/AgentCore/Classification/Services/IntentClassifierService.php
                 app/Modules/AgentCore/Tests/IntentClassifierServiceTest.php
Methods        : classify(message, tenantId, context): IntentResultDTO
                 buildPrompt(message, context): string
Interface Impl : IntentClassifierInterface → IntentClassifierService
Prompt Version : v1.0 (PROMPT_TEMPLATE const, fallback sebelum 3.12 PromptVersioningService)
Valid Intents  : 20 (VALID_INTENTS const)
Tests Pass     : 13 / 13
Commit         : feat: IntentClassifierService — LLM intent classifier, MockLlmAdapter test, 20 valid intents

CATATAN PENTING:
- PRINSIP 9: semua test pakai MockLlmAdapter, 0 real OpenAI calls
- classify() call complete() bukan completeJson() — parse JSON secara manual dengan repair fallback
- Fallback: unknown intent → unclear_message (confidence 0.0)
- TokenUsageLogger dipanggil per classify()
- Context injection: max 5 pesan terakhir di-inject ke prompt
- Anti-injection instruction sudah ada di PROMPT_TEMPLATE
- Binding: IntentClassifierInterface → IntentClassifierService di AgentCoreServiceProvider
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
Status         : [x] DONE — 2026-05-14
Files Created  : app/Modules/AgentCore/Extraction/Services/EntityExtractionService.php
                 app/Modules/AgentCore/Tests/EntityExtractionServiceTest.php
Interface Impl : EntityExtractorInterface
Methods Exposed: extract(message, tenantId, existingEntities, context): EntityResultDTO
                 normalizeDate(rawDate, timezone): ?string
                 normalizeBudget(rawBudget): ?int
Prompt Version : v1.0 (hardcoded, DB override in sub-task 3.12)
Tests Pass     : 23 / 23
Commit         : feat: EntityExtractionService — wedding entity extraction, normalization, entity merge
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

[Phase 1 selesai — 2026-05-11]:
- DTO fields yang sudah frozen: 19 DTO di app/Modules/Shared/DTOs/ — field TIDAK BOLEH diubah tanpa update CLAUDE.md
- Interface contract final: 9 interface di app/Modules/Shared/Contracts/ — semua impl Phase 3 wajib implements
- WA Gateway contract v1.0: POST /webhook/inbound | POST /dispatch | GET /status/:wa_account_id
- MockLlmAdapter: app/Modules/AgentCore/LLM/Adapters/MockLlmAdapter.php — WAJIB untuk semua unit test LLM
- Filament 5.x (bukan 3.x) — form() pakai Schema, Actions di Filament\Actions\*
- SUPERADMIN_PASSWORD di .env = 'change_this_password' (ganti di production)
- Hal penting Phase 2:
  * Tables: packages, package_prices, faqs, knowledge_items, assets (semua extend TenantBaseModel)
  * Full-text search: PostgreSQL tsvector (skip Elasticsearch)
  * pgvector: Phase 3+, skip di Phase 2
  * TenantConfigResolver harus support default fallback per PolicyKey

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
