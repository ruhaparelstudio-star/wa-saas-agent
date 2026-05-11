# KANBAN-PHASE-1.md — Contracts & Foundation
# PRE-REQUISITE: Phase 0 gate harus OPEN di PROGRESS.md
# Target: Semua contract defined, Docker up, Auth & Tenant berjalan
# Exit Gate: php artisan test 100% pass, docker compose up OK

---

## CEK SEBELUM MULAI

```
Buka PROGRESS.md, pastikan:
[ ] Integration Checkpoint Phase 0 → Gate: OPEN
[ ] Semua prompt sudah di PROMPTS.md
[ ] Git tag v0.1-poc-complete sudah ada

Jika belum: selesaikan Phase 0 dulu.
```

---

### SUB-TASK 1.1 — Project Laravel + Struktur Modul
**Status:** [x] DONE — 2026-05-11
**Depends On:** Phase 0 gate OPEN
**Estimated Time:** 1-2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — SELURUHNYA, terutama:
   - Bagian "STRUKTUR FOLDER MODULE"
   - Bagian "NAMING CONVENTION"
   - Bagian "RULES WAJIB UNTUK CLAUDE CODE"
2. PROGRESS.md — cek status dan catatan Phase 0
3. GITFLOW.md — baca bagian "SETUP AWAL" dan "COMMIT CONVENTION"

Required files yang harus ada:
[ ] CLAUDE.md — ada?
[ ] PROGRESS.md — ada?
[ ] GITFLOW.md — ada?

Konfirmasi:
- Apa struktur folder yang wajib untuk setiap module?
- Apa naming convention untuk Service class?
- Apa perbedaan BaseModel dan TenantBaseModel?
```

---

**CODING PROMPT:**
```
Inisialisasi project Laravel 11 di folder laravel-app/.

STEP 1 — Buat project Laravel:
composer create-project laravel/laravel laravel-app
cd laravel-app

STEP 2 — Konfigurasi PostgreSQL:
Ganti di config/database.php: default connection = pgsql
Update .env.example dengan semua variabel dari CLAUDE.md:
  APP_NAME=WA_SaaS_AI_Agent
  APP_ENV=local
  APP_DEBUG=true
  APP_URL=http://localhost:8080
  DB_CONNECTION=pgsql
  DB_HOST=postgres
  DB_PORT=5432
  DB_DATABASE=wa_agent
  DB_USERNAME=wa_agent
  DB_PASSWORD=secret
  CACHE_STORE=redis
  QUEUE_CONNECTION=redis
  SESSION_DRIVER=redis
  REDIS_HOST=redis
  REDIS_PORT=6379
  WA_GATEWAY_URL=http://wa-gateway:3001
  WA_INTERNAL_SECRET=change_this_to_random_32_chars
  LLM_PROVIDER=openai
  LLM_API_KEY=
  LLM_CLASSIFIER_MODEL=gpt-4o-mini
  LLM_COMPOSER_MODEL=gpt-4o
  LLM_EMBEDDING_MODEL=text-embedding-3-small
  LLM_TIMEOUT=30
  LLM_MAX_RETRIES=3
  SUPERADMIN_EMAIL=admin@platform.com
  SUPERADMIN_PASSWORD=change_this_password
  FILESYSTEM_DISK=local

STEP 3 — Buat artisan command: php artisan module:make {ModuleName}

File: app/Console/Commands/MakeModuleCommand.php
- Signature: module:make {name : Nama module (PascalCase)}
- Buat folder structure:
  app/Modules/{Name}/
    Actions/
    DTOs/
    Enums/
    Events/
    Jobs/
    Models/
    Repositories/
    Services/
    Tests/
  app/Modules/{Name}/routes.php (template kosong)
  app/Modules/{Name}/{Name}ServiceProvider.php (template dasar)
- Output: "Module {Name} created successfully."

STEP 4 — Buat module Shared secara manual (bukan via command):
app/Modules/Shared/
  Models/
  Models/Traits/
  Scopes/
  Contracts/
  DTOs/
  Enums/
  Http/Controllers/

STEP 5 — Update composer.json autoload:
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "App\\Modules\\": "app/Modules/"
    }
}

STEP 6 — Bootstrap module loading di AppServiceProvider.php:
Di register(): scan app/Modules/*/Providers/*ServiceProvider.php dan register
Di boot(): scan app/Modules/*/routes.php dan load

STEP 7 — Install dependencies:
composer require laravel/sanctum
composer require filament/filament:"^3.2"
composer require spatie/laravel-permission (untuk role)
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

Jangan buat migration atau model apapun di sub-task ini.
Hanya scaffolding.
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan module:make TestModule → folder terbuat di app/Modules/TestModule/?
2. php artisan → tidak ada error
3. php artisan list → command module:make muncul?
4. .env.example berisi semua variabel dari CLAUDE.md?
5. composer.json autoload sudah include app/Modules/?
6. Hapus TestModule setelah verifikasi: rm -rf app/Modules/TestModule

Laporkan hasilnya.
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.1:
- Status: [x] DONE
- Files Created: [list]
- Command Works: [x]

File Registry tambahkan:
app/Console/Commands/MakeModuleCommand.php | 1.1 | php artisan module:make

KANBAN-PHASE-1.md: [x] DONE

Git:
git add laravel-app/ PROGRESS.md KANBAN-PHASE-1.md
git commit -m "chore: inisialisasi Laravel 11, struktur modul, artisan module:make command"
```

---

### SUB-TASK 1.2 — Semua Enum
**Status:** [x] DONE — 2026-05-11
**Depends On:** 1.1 selesai
**Estimated Time:** 45 menit

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — bagian "ENUM REFERENCE LENGKAP"
2. PROGRESS.md — cek File Registry dari 1.1
3. Verifikasi folder ada: ls app/Modules/Shared/Enums/

Depends on check:
[ ] app/Modules/Shared/Enums/ folder ada
[ ] php artisan berjalan tanpa error

Konfirmasi: sebutkan semua enum yang harus dibuat beserta cases-nya.
```

---

**CODING PROMPT:**
```
Buat SEMUA enum di app/Modules/Shared/Enums/.
Ikuti PERSIS nama dan cases dari CLAUDE.md bagian "ENUM REFERENCE LENGKAP".

Satu file per enum. Format PHP 8.1 backed enum (string).

Template setiap enum:
<?php

namespace App\Modules\Shared\Enums;

enum NamaEnum: string
{
    case CASE_NAME = 'case_value';

    public function label(): string
    {
        return match($this) {
            self::CASE_NAME => 'Label',
        };
    }
}

Enum yang harus dibuat (15 file):
1. UserRole.php        — SUPERADMIN='superadmin', TENANT_ADMIN='tenant_admin'
2. TenantStatus.php    — TRIAL, ACTIVE, EXPIRED, SUSPENDED
3. FeatureKey.php      — MAX_WA_AGENTS, MONTHLY_LEAD_LIMIT, GOOGLE_CALENDAR_ENABLED,
                         FOLLOW_UP_AUTOMATION, ANALYTICS_ADVANCED, MULTI_CHANNEL
4. WaAccountStatus.php — DISCONNECTED, QR_PENDING, CONNECTING, CONNECTED,
                         RECONNECTING, FAILED, BANNED_OR_RESTRICTED
5. ConversationStage.php — NEW_LEAD, EXPLORATION, QUALIFICATION, RECOMMENDATION,
                          CONSIDERATION, BOOKING, WAITING_BOOKING, CLOSED,
                          INVOICE_PHASE, POST_INVOICE_LIMITED, HANDOFF, PAUSED_ADMIN
6. AgentMode.php       — ACTIVE, PAUSED, HANDOFF, LIMITED
7. MemoryMode.php      — ACTIVE, DORMANT
8. LeadTemperature.php — COLD, WARM, HOT
9. HandoffPriority.php — LOW, MEDIUM, HIGH, URGENT
10. HandoffStatus.php  — PENDING, IN_PROGRESS, RESOLVED
11. TenantTone.php     — FORMAL, SEMI_FORMAL, FRIENDLY, CASUAL
12. PolicyKey.php      — PRICELIST_MODE, PRICELIST_MIN_REQUIREMENT,
                         LEAD_LIMIT_FALLBACK, AFTER_HOURS_BEHAVIOR,
                         INVOICE_MAX_RESEND, CONCURRENT_BOOKING_LOCK
13. AssetType.php      — PRICELIST, BROCHURE, PORTFOLIO, OTHER
14. NotificationType.php — HANDOFF_REQUIRED, MESSAGE_WHILE_PAUSED, WA_DISCONNECTED,
                          CALENDAR_ERROR, INVOICE_ACTION, BOOKING_ACTION,
                          INJECTION_ATTEMPT_DETECTED
15. MessageType.php    — TEXT, IMAGE, AUDIO, DOCUMENT, VIDEO, STICKER

Setelah membuat semua, verifikasi dengan:
php -l app/Modules/Shared/Enums/UserRole.php
(lakukan untuk semua file)
```

---

**QA PROMPT:**
```
Verifikasi:
1. ls app/Modules/Shared/Enums/ → 15 file ada?
2. php -l app/Modules/Shared/Enums/*.php → semua "No syntax errors"?
3. Buat test manual di tinker:
   php artisan tinker
   >>> App\Modules\Shared\Enums\UserRole::SUPERADMIN->value
   // Expected: 'superadmin'
   >>> App\Modules\Shared\Enums\ConversationStage::NEW_LEAD->label()
   // Expected: tidak error
4. Jumlah case di ConversationStage: 12? (sesuai CLAUDE.md)
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md:
- Sub-task 1.2: [x] DONE
- Enum Count: 15 / 15
- File Registry: tambahkan semua 15 file

KANBAN-PHASE-1.md: [x] DONE

Git commit:
git add app/Modules/Shared/Enums/ PROGRESS.md KANBAN-PHASE-1.md
git commit -m "feat: buat semua shared enums (15 enum)"
```

---

### SUB-TASK 1.3 — Semua DTO
**Status:** [x] DONE — 2026-05-11
**Depends On:** 1.2 selesai (semua enum ada)
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — bagian "CONTRACT — DTO FIELDS (FROZEN SETELAH PHASE 1)"
2. PROGRESS.md — cek File Registry, pastikan semua 15 enum ada
3. app/Modules/Shared/Enums/ — list semua file yang ada

Depends on check:
[ ] 15 enum file ada di app/Modules/Shared/Enums/

PENTING: DTO fields di CLAUDE.md adalah CONTRACT.
Setelah Phase 1 selesai, field ini TIDAK BOLEH diubah.
Baca dengan sangat teliti sebelum mulai.

Konfirmasi: sebutkan semua field IntentResultDTO dan TurnContextDTO.
```

---

**CODING PROMPT:**
```
Buat SEMUA DTO di app/Modules/Shared/DTOs/.
Field HARUS PERSIS sesuai CLAUDE.md bagian CONTRACT.
TIDAK BOLEH tambah atau kurang field.

Template setiap DTO:
<?php

namespace App\Modules\Shared\DTOs;

readonly class NamaDTO
{
    public function __construct(
        public string $field1,
        public ?string $field2,
        // ... semua field
    ) {}

    public static function from(array $data): static
    {
        return new static(
            field1: $data['field1'] ?? '',
            field2: $data['field2'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'field1' => $this->field1,
            'field2' => $this->field2,
        ];
    }
}

DTO yang harus dibuat (19 file):
1. IntentResultDTO.php
2. EntityResultDTO.php
3. DecisionDTO.php
4. BlockedActionDTO.php
5. TurnContextDTO.php
6. TurnResultDTO.php
7. GroundedKnowledgeDTO.php
8. GroundingRefDTO.php
9. ComposedReplyDTO.php
10. ValidatorResultDTO.php
11. InboundMessageDTO.php
12. LlmResponseDTO.php
13. LlmEmbeddingDTO.php
14. AvailabilityResultDTO.php
15. TenantDTO.php
16. ConversationDTO.php
17. ConversationStateDTO.php
18. LeadProfileDTO.php
19. TenantConfigDTO.php

Gunakan Enum yang sudah ada untuk type hint yang relevan.
Contoh: AgentMode $agentMode, ConversationStage $stage, dll.

Setelah selesai, buat test sederhana:
php artisan tinker
>>> $dto = App\Modules\Shared\DTOs\IntentResultDTO::from(['intent' => 'greeting', 'confidence' => 0.95, 'reason' => 'test', 'raw_response' => '{}'])
>>> $dto->intent // 'greeting'
>>> $dto->toArray() // array dengan semua field
```

---

**QA PROMPT:**
```
Verifikasi:
1. ls app/Modules/Shared/DTOs/ → 19 file ada?
2. php -l app/Modules/Shared/DTOs/*.php → semua no syntax errors?
3. Cek field IntentResultDTO vs CLAUDE.md: apakah PERSIS sama?
4. Cek field TurnContextDTO vs CLAUDE.md: apakah PERSIS sama?
5. Test from() dan toArray() bekerja untuk 3 DTO berbeda
6. Ada field yang berbeda dari CLAUDE.md? Jika ya: FIX sekarang

PENTING: Jika ada field yang berbeda dari CLAUDE.md, fix sekarang.
Field ini akan di-freeze setelah Phase 1 selesai.
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md:
- Sub-task 1.3: [x] DONE
- DTO Count: 19 / 19
- "Verified vs CLAUDE.md": [x]
- File Registry: tambahkan semua 19 file

CATATAN PENTING di PROGRESS.md:
"DTO fields sudah FROZEN sesuai CLAUDE.md v2.0.
Sub-task berikutnya TIDAK BOLEH ubah field DTO.
Jika perlu ubah: update CLAUDE.md terlebih dahulu dan diskusikan."

KANBAN-PHASE-1.md: [x] DONE

Git commit:
git add app/Modules/Shared/DTOs/ PROGRESS.md KANBAN-PHASE-1.md
git commit -m "feat: buat semua shared DTOs (19 DTO) — fields frozen"
```

---

### SUB-TASK 1.4 — Semua Interface
**Status:** [x] DONE — 2026-05-11
**Depends On:** 1.3 selesai (semua DTO ada)
**Estimated Time:** 1 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — bagian "PRINSIP 4" dan Contract Interface
2. PROGRESS.md
3. app/Modules/Shared/DTOs/ — list semua DTO
4. app/Modules/Shared/Enums/ — list semua enum

Depends on check:
[ ] 19 DTO file ada
[ ] 15 enum file ada

Konfirmasi: apa saja interface yang harus dibuat dan method-nya?
```

---

**CODING PROMPT:**
```
Buat 9 interface di app/Modules/Shared/Contracts/.

Setiap interface harus menggunakan type hint dari DTO dan Enum yang sudah ada.

1. LlmClientInterface.php:
   complete(string $prompt, array $options = []): LlmResponseDTO
   completeJson(string $prompt, array $options = []): array
   embed(string $text): LlmEmbeddingDTO

2. ChannelGatewayInterface.php:
   sendText(string $accountId, string $toPhone, string $body): bool
   sendFile(string $accountId, string $toPhone, string $fileUrl, string $caption = ''): bool
   getStatus(string $accountId): WaAccountStatus

3. CalendarProviderInterface.php:
   checkAvailability(string $date, string $tenantId): AvailabilityResultDTO
   getEventsOnDate(string $date, string $calendarId): array

4. StorageProviderInterface.php:
   upload(mixed $file, string $path): string
   getUrl(string $path): string
   delete(string $path): bool

5. IntentClassifierInterface.php:
   classify(string $message, string $tenantId, array $conversationContext = []): IntentResultDTO

6. EntityExtractorInterface.php:
   extract(string $message, string $tenantId, array $existingEntities = [], array $context = []): EntityResultDTO

7. KnowledgeRetrieverInterface.php:
   retrieve(string $intent, array $entities, string $tenantId): GroundedKnowledgeDTO

8. DecisionEngineInterface.php:
   decide(TurnContextDTO $context): DecisionDTO

9. ResponseComposerInterface.php:
   compose(TurnContextDTO $context, DecisionDTO $decision, ValidatorResultDTO $validatorResult): ComposedReplyDTO

Setiap interface:
- Namespace: App\Modules\Shared\Contracts
- Import semua DTO dan Enum yang dipakai
- PHPDoc di setiap method
```

---

**QA PROMPT:**
```
Verifikasi:
1. ls app/Modules/Shared/Contracts/ → 9 file ada?
2. php -l app/Modules/Shared/Contracts/*.php → semua no syntax errors?
3. Cek import: apakah semua DTO dan Enum diimport dengan benar?
4. Buat class dummy yang implements LlmClientInterface → tidak ada error?
5. Semua return type menggunakan DTO/Enum yang sudah ada?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.4.
CATATAN PENTING: "Interface contract final. Semua implementasi
di Phase 3 WAJIB implements interface ini."

File Registry: tambahkan 9 interface.

KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: buat semua shared contracts/interfaces (9 interface)"
```

---

### SUB-TASK 1.5 — Base Classes + TenantScope + Health Endpoints
**Status:** [x] DONE — 2026-05-11
**Depends On:** 1.4 selesai
**Estimated Time:** 1 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 3 (Tenant Isolation), PRINSIP 5 (UUID)
2. PROGRESS.md
3. app/Modules/Shared/Enums/UserRole.php

Depends on check:
[ ] UserRole enum ada
[ ] Interface files ada

Konfirmasi: bagaimana TenantScope bekerja untuk superadmin?
```

---

**CODING PROMPT:**
```
Buat base classes di app/Modules/Shared/.

1. app/Modules/Shared/Models/Traits/HasUuid.php:
   namespace App\Modules\Shared\Models\Traits;
   use Illuminate\Support\Str;
   trait HasUuid {
     protected static function bootHasUuid(): void {
       static::creating(fn($model) => $model->{$model->getKeyName()} ??= Str::uuid()->toString());
     }
     public function getIncrementing(): bool { return false; }
     public function getKeyType(): string { return 'string'; }
   }

2. app/Modules/Shared/Models/BaseModel.php:
   - Extend Eloquent Model
   - Use HasFactory, HasUuid
   - protected $keyType = 'string'
   - public $incrementing = false
   - protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime']

3. app/Modules/Shared/Scopes/TenantScope.php:
   - Implements Scope
   - apply(): jika user bukan superadmin, tambahkan where('tenant_id', auth()->user()?->tenant_id)
   - Jika user = null atau superadmin: tidak apply scope
   - Static method: withoutTenant() untuk query tanpa scope

4. app/Modules/Shared/Models/TenantBaseModel.php:
   - Extend BaseModel
   - Override booted(): apply TenantScope sebagai global scope
   - protected string $tenantColumn = 'tenant_id'

5. app/Modules/Shared/Http/Controllers/HealthController.php:
   - GET /health → {status: ok, service: app, timestamp: now()}
   - GET /health/db → coba DB::connection()->getPdo(), return ok/error
   - GET /health/redis → coba Redis::ping(), return ok/error
   - GET /health/queue → return {status: ok, driver: config('queue.default')}
   - GET /health/wa-gateway → HTTP GET ke WA_GATEWAY_URL/health, return ok/error

6. Routes di app/Modules/Shared/routes.php:
   Route::prefix('health')->group(function() {
     Route::get('/', [HealthController::class, 'index']);
     Route::get('/db', [HealthController::class, 'database']);
     Route::get('/redis', [HealthController::class, 'redis']);
     Route::get('/queue', [HealthController::class, 'queue']);
     Route::get('/wa-gateway', [HealthController::class, 'waGateway']);
   });

7. Tests di app/Modules/Shared/Tests/BaseModelTest.php:
   - UUID auto-generated
   - TenantScope memfilter per tenant_id
   - Superadmin tidak difilter TenantScope
   - Health endpoints return 200 + JSON

Jangan buat migration apapun di sub-task ini.
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter=BaseModelTest → semua pass?
2. Buat dummy model extend TenantBaseModel, buat record → UUID ter-generate?
3. php artisan tinker → test TenantScope:
   >>> Auth::loginUsingId($tenantAdminUserId)
   >>> YourModel::all() // harus filter tenant_id otomatis
4. Health endpoints (setelah Docker up nanti):
   curl http://localhost:8080/health → {status: ok}
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.5.
File Registry: tambahkan HasUuid, BaseModel, TenantBaseModel, TenantScope, HealthController.
KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: base classes, TenantScope, health endpoints"
```

---

### SUB-TASK 1.6 — Docker Compose + Environment
**Status:** [x] DONE — 2026-05-11
**Depends On:** 1.5 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — tech stack dan semua service yang dibutuhkan
2. SETUP.md — bagian Docker dan WSL2
3. PROGRESS.md

Konfirmasi: sebutkan semua Docker service yang harus ada.
```

---

**CODING PROMPT:**
```
Buat konfigurasi Docker lengkap di root project (bukan di laravel-app/).

1. docker-compose.yml — 9 services:
   app:
     build: ./docker/php
     volumes: [".:/var/www/html"]
     networks: [saas_network]
     depends_on: [postgres, redis]
     env_file: [./laravel-app/.env]

   nginx:
     image: nginx:alpine
     ports: ["8080:80"]
     volumes: ["./laravel-app:/var/www/html", "./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf"]
     depends_on: [app]
     networks: [saas_network]

   postgres:
     image: postgres:16-alpine
     environment:
       POSTGRES_DB: wa_agent
       POSTGRES_USER: wa_agent
       POSTGRES_PASSWORD: secret
     ports: ["5432:5432"]
     volumes: ["postgres_data:/var/lib/postgresql/data"]
     healthcheck:
       test: ["CMD-SHELL", "pg_isready -U wa_agent"]
       interval: 10s
       timeout: 5s
       retries: 5
     networks: [saas_network]

   redis:
     image: redis:alpine
     ports: ["6379:6379"]
     healthcheck:
       test: ["CMD", "redis-cli", "ping"]
     networks: [saas_network]

   queue:
     build: ./docker/php
     command: php artisan queue:work --tries=3 --timeout=120 --sleep=3
     volumes: ["./laravel-app:/var/www/html"]
     depends_on: [app, redis, postgres]
     networks: [saas_network]

   scheduler:
     build: ./docker/php
     command: sh -c "while true; do php artisan schedule:run --no-interaction; sleep 60; done"
     volumes: ["./laravel-app:/var/www/html"]
     depends_on: [app]
     networks: [saas_network]

   horizon:
     build: ./docker/php
     command: php artisan horizon
     volumes: ["./laravel-app:/var/www/html"]
     depends_on: [app, redis]
     networks: [saas_network]

   wa-gateway:
     build: ./wa-gateway
     ports: ["3001:3001"]
     volumes: ["./wa-gateway:/app", "wa_sessions:/app/sessions"]
     environment:
       LARAVEL_URL: http://app
       INTERNAL_SECRET: "${WA_INTERNAL_SECRET}"
       PORT: 3001
     depends_on: [app]
     networks: [saas_network]

   mailpit:
     image: axllent/mailpit
     ports: ["8025:8025", "1025:1025"]
     networks: [saas_network]

   pgadmin:
     image: dpage/pgadmin4
     ports: ["5050:80"]
     environment:
       PGADMIN_DEFAULT_EMAIL: admin@admin.com
       PGADMIN_DEFAULT_PASSWORD: admin
     networks: [saas_network]

   networks:
     saas_network:
       driver: bridge

   volumes:
     postgres_data:
     wa_sessions:

2. docker/php/Dockerfile:
   FROM php:8.3-fpm-alpine
   Install: pdo_pgsql, pgsql, redis, bcmath, gd, zip, intl, pcntl
   Install composer
   WORKDIR /var/www/html

3. docker/php/entrypoint.sh:
   #!/bin/sh
   cd /var/www/html
   if [ ! -f "vendor/autoload.php" ]; then composer install --no-dev --optimize-autoloader; fi
   if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then php artisan key:generate; fi
   php artisan migrate --force --no-interaction
   php artisan storage:link --no-interaction 2>/dev/null || true
   exec php-fpm

4. docker/nginx/default.conf:
   Standard Laravel nginx config dengan try_files dan FastCGI pass ke app:9000

5. wa-gateway/ skeleton:
   wa-gateway/package.json (dependencies: express, axios)
   wa-gateway/index.js:
     Express server port 3001
     GET /health → {status: ok, timestamp: Date.now()}
     POST /sessions/start → {status: pending, session_id: req.body.wa_account_id}
     GET /sessions/:id/status → {status: 'disconnected', wa_account_id: req.params.id}
     POST /messages/send → {queued: true, message_id: uuid()}

6. Makefile di root:
   up: docker compose up -d
   down: docker compose down
   bash: docker compose exec app sh
   test: docker compose exec app php artisan test
   fresh: docker compose exec app php artisan migrate:fresh --seed
   logs: docker compose logs -f
   horizon-logs: docker compose logs -f horizon
   tinker: docker compose exec app php artisan tinker
   build: docker compose up -d --build
```

---

**QA PROMPT:**
```
Verifikasi:
1. make build → semua container start tanpa error?
2. docker compose ps → semua service "running" atau "healthy"?
3. curl http://localhost:8080/health → {status: ok}?
4. curl http://localhost:8080/health/db → {status: ok}?
5. curl http://localhost:8080/health/redis → {status: ok}?
6. curl http://localhost:3001/health → {status: ok}?
7. Buka http://localhost:8025 → Mailpit UI muncul?
8. make test → php artisan test pass?

Jika ada container yang tidak start: jalankan docker compose logs [service-name]
dan laporkan error.
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.6:
- Docker Up: [x]
- Semua health check: [x]

File Registry: tambahkan docker-compose.yml, Dockerfile, nginx config, wa-gateway/index.js

KANBAN-PHASE-1.md: [x] DONE
Git commit: "chore: Docker Compose setup lengkap, wa-gateway skeleton"
```

---

### SUB-TASK 1.7 — Auth & Role System
**Status:** [ ] TODO
**Depends On:** 1.6 selesai (Docker running)
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — UserRole enum, naming convention
2. PROGRESS.md — File Registry, pastikan Docker running
3. app/Modules/Shared/Enums/UserRole.php
4. app/Modules/Shared/Models/BaseModel.php

Depends on check:
[ ] docker compose ps → semua running
[ ] UserRole enum ada
[ ] BaseModel ada

Konfirmasi: apa perbedaan BaseModel dan TenantBaseModel?
Kapan User harus extend BaseModel (bukan TenantBaseModel)?
```

---

**CODING PROMPT:**
```
Buat Auth module di app/Modules/Auth/.

Jalankan: php artisan module:make Auth

1. Migration (di laravel-app/database/migrations/):
   create_users_table:
   id (uuid), name (string), email (unique string),
   email_verified_at (nullable timestamp), password (string),
   role (enum: superadmin, tenant_admin), is_active (bool default true),
   last_login_at (nullable timestamp), remember_token (string nullable),
   timestamps

2. app/Modules/Auth/Models/User.php:
   - Extend BaseModel (BUKAN TenantBaseModel — user tidak punya tenant_id)
   - Use HasFactory, Notifiable, HasApiTokens
   - implements Authenticatable (pakai AuthenticatableTrait)
   - protected $hidden = ['password', 'remember_token']
   - protected $casts = ['role' => UserRole::class, 'is_active' => 'boolean',
                         'last_login_at' => 'datetime', 'email_verified_at' => 'datetime']
   - Scope: scopeActive($query) → where('is_active', true)
   - Method: isSuperadmin(): bool
   - Method: isTenantAdmin(): bool

3. app/Modules/Auth/DTOs/UserDTO.php:
   Fields: id, name, email, role (UserRole), is_active, last_login_at

4. app/Modules/Auth/Services/AuthService.php:
   login(string $email, string $password): array
     - Cari user by email, cek is_active, cek password
     - Update last_login_at
     - Buat Sanctum token
     - Return: ['token' => string, 'user' => UserDTO, 'expires_at' => Carbon]
   logout(User $user): void
     - Revoke current token
   me(User $user): UserDTO
     - Return UserDTO dari user

5. app/Modules/Auth/Http/Requests/LoginRequest.php:
   email: required|email
   password: required|string

6. app/Modules/Auth/Http/Controllers/AuthController.php:
   POST /api/auth/login → login()
   POST /api/auth/logout → logout() [auth:sanctum]
   GET /api/auth/me → me() [auth:sanctum]

7. app/Modules/Auth/Http/Middleware/:
   SuperadminOnly.php → cek $user->isSuperadmin(), jika tidak: 403
   TenantAdminOnly.php → cek $user->isTenantAdmin(), jika tidak: 403
   ActiveUserOnly.php → cek $user->is_active, jika tidak: 401

8. Database/Seeders/SuperadminSeeder.php:
   Buat user superadmin dari env:
   SUPERADMIN_EMAIL (default: admin@platform.com)
   SUPERADMIN_PASSWORD (default: Password123! — HARUS DIGANTI)

9. app/Modules/Auth/routes.php:
   Route::prefix('api/auth')->group(function() { ... })

10. Tests (app/Modules/Auth/Tests/AuthServiceTest.php):
    - Superadmin bisa login → dapat token
    - Tenant admin bisa login → dapat token
    - Wrong password → AuthenticationException
    - Inactive user → AuthenticationException
    - Token valid untuk /api/auth/me
    - Logout → token tidak valid lagi
    - SuperadminOnly middleware block tenant_admin → 403
    - TenantAdminOnly middleware block superadmin → 403

Jalankan setelah selesai:
make fresh → migrate + seed
make test → semua test pass
```

---

**QA PROMPT:**
```
Verifikasi:
1. make fresh → berhasil?
2. make test --filter=AuthServiceTest → semua pass?
3. Manual test:
   curl -X POST http://localhost:8080/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@platform.com","password":"Password123!"}' | jq
   → Dapat token?

4. curl -X GET http://localhost:8080/api/auth/me \
     -H "Authorization: Bearer [token]" | jq
   → Dapat user data dengan role superadmin?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.7.
File Registry: User model, AuthService, AuthController, middleware.
KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: auth system dengan role, Sanctum token, middleware"
```

---

### SUB-TASK 1.8 — Tenant + Activation System
**Status:** [ ] TODO
**Depends On:** 1.7 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — TenantStatus enum, TenantBaseModel concept
2. PROGRESS.md
3. app/Modules/Auth/Models/User.php
4. app/Modules/Shared/Models/TenantBaseModel.php
5. app/Modules/Shared/Enums/TenantStatus.php

Konfirmasi: kenapa Tenant extend BaseModel, bukan TenantBaseModel?
```

---

**CODING PROMPT:**
```
Buat Tenancy module. Jalankan: php artisan module:make Tenancy

1. Migrations:
   create_tenants_table:
     id uuid, name string, slug unique string, status (TenantStatus enum),
     industry varchar default 'wedding', contact_email string,
     contact_phone nullable string, created_by_id uuid FK users,
     timestamps

   create_tenant_users_table:
     id uuid, tenant_id FK tenants, user_id FK users,
     role varchar, is_primary bool default false, timestamps
     unique: [tenant_id, user_id]

   create_activation_tokens_table:
     id uuid, tenant_id FK tenants, token varchar unique (hashed),
     expires_at timestamp, used_at nullable timestamp, created_at timestamp

2. Models:
   Tenant.php (extend BaseModel):
     Cast: status → TenantStatus
     Relations: users(), tenantUsers(), createdBy()
     Scope: active() → where status ACTIVE
     Scope: trial() → where status TRIAL
     Method: isActive(): bool
     Method: canAutomate(): bool (ACTIVE atau TRIAL)

   TenantUser.php (extend BaseModel)
   ActivationToken.php (extend BaseModel):
     Method: isExpired(): bool → expires_at < now()
     Method: isUsed(): bool → used_at !== null
     Method: isValid(): bool → !isExpired() && !isUsed()

3. ActivationService.php:
   generateToken(Tenant $tenant): ActivationToken
     - raw token: Str::random(64)
     - hashed: hash('sha256', $rawToken)
     - simpan hashed ke DB
     - expires_at: now()->addHours(48)
     - Return ActivationToken (dengan raw token untuk email)

   validateToken(string $rawToken): ?ActivationToken
     - Hash rawToken → cari di DB
     - Return null jika tidak ditemukan atau tidak valid

   activate(string $rawToken, string $password): User
     - Validate token
     - Set password user tenant admin
     - Mark token as used (used_at = now())
     - Set tenant status ACTIVE
     - Return user

   resendActivation(Tenant $tenant): ActivationToken
     - Invalidate semua token lama tenant ini
     - Generate token baru
     - Kirim email baru

4. TenantService.php:
   create(array $data, User $createdBy): Tenant
     - Buat Tenant record
     - Buat User (role tenant_admin, is_active true, password random)
     - Buat TenantUser pivot
     - Generate activation token
     - Kirim ActivationEmail

   updateStatus(Tenant $tenant, TenantStatus $status): Tenant

5. ActivationEmail.php (Mailable):
   - To: tenant contact_email
   - Subject: "Aktivasi Akun Anda di Platform Kami"
   - Link: APP_URL . '/activate/' . $rawToken

6. Controllers:
   SuperadminTenantController.php:
     GET /api/superadmin/tenants [superadmin only]
     POST /api/superadmin/tenants [superadmin only]
     GET /api/superadmin/tenants/{id} [superadmin only]
     PUT /api/superadmin/tenants/{id}/status [superadmin only]
     POST /api/superadmin/tenants/{id}/resend-activation [superadmin only]

   ActivationController.php:
     GET /activate/{token} → show (tampilkan form set password)
     POST /activate/{token} → activate (proses set password)

7. Tests:
   - Superadmin bisa create tenant
   - Token ter-generate setelah create
   - Token expired setelah 48 jam → ditolak
   - Token hanya bisa dipakai sekali → second use ditolak
   - Token lama invalid setelah resend
   - Activate dengan token valid → user bisa login
   - Tenant isolation: API tenant hanya return miliknya
```

---

**QA PROMPT:**
```
Verifikasi:
1. make fresh → berhasil?
2. make test --filter=Tenancy → semua pass?
3. Manual flow:
   a. Create tenant via API superadmin
   b. Cek activation email di Mailpit (http://localhost:8025)
   c. Simulate activation dengan token
   d. Login sebagai tenant admin
4. Tenant isolation: buat 2 tenant, login sebagai tenant A,
   coba akses data tenant B via API → 403?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.8.
KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: tenant management dan activation system"
```

---

### SUB-TASK 1.9 — Plan & Feature Gating
**Status:** [ ] TODO
**Depends On:** 1.8 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — FeatureKey enum
2. PROGRESS.md
3. app/Modules/Shared/Enums/FeatureKey.php
4. app/Modules/Tenancy/Models/Tenant.php

Konfirmasi: apa semua FeatureKey yang ada dan tipe datanya (bool vs integer)?
```

---

**CODING PROMPT:**
```
Buat Plans module. Jalankan: php artisan module:make Plans

1. Migrations:
   create_plans_table:
     id uuid, code unique varchar, name varchar, description text nullable,
     is_active bool default true, sort_order int default 0, timestamps

   create_plan_features_table:
     id uuid, plan_id FK plans, feature_key (FeatureKey enum),
     feature_value varchar, timestamps
     unique: [plan_id, feature_key]

   create_tenant_subscriptions_table:
     id uuid, tenant_id unique FK tenants, plan_id FK plans,
     status enum (active/trial/expired/cancelled),
     starts_at timestamp, ends_at nullable timestamp,
     trial_ends_at nullable timestamp, timestamps

2. Models:
   Plan.php (extend BaseModel)
   PlanFeature.php (extend BaseModel):
     Cast: feature_key → FeatureKey
   TenantSubscription.php (extend BaseModel):
     Methods: isActive(), isExpired(), isTrial()

3. PlanSeeder.php:
   Starter: MAX_WA_AGENTS=1, MONTHLY_LEAD_LIMIT=100,
            GOOGLE_CALENDAR_ENABLED=false, FOLLOW_UP_AUTOMATION=false
   Growth:  MAX_WA_AGENTS=2, MONTHLY_LEAD_LIMIT=500,
            GOOGLE_CALENDAR_ENABLED=true, FOLLOW_UP_AUTOMATION=true
   Pro:     MAX_WA_AGENTS=5, MONTHLY_LEAD_LIMIT=-1,
            GOOGLE_CALENDAR_ENABLED=true, FOLLOW_UP_AUTOMATION=true,
            ANALYTICS_ADVANCED=true

4. FeatureGateService.php:
   check(string $tenantId, FeatureKey $feature): bool
   getValue(string $tenantId, FeatureKey $feature): mixed
   canAddWaAgent(string $tenantId, int $currentCount): bool
   isLeadLimitUnlimited(string $tenantId): bool
   getLeadLimit(string $tenantId): int
   isCalendarEnabled(string $tenantId): bool
   isFollowUpEnabled(string $tenantId): bool

   Cache hasil 5 menit di Redis untuk performance.

5. Middleware: CheckFeatureEnabled.php
   Constructor: FeatureKey $feature
   Jika feature disabled: return 403 dengan message

6. Endpoint superadmin:
   POST /api/superadmin/tenants/{id}/assign-plan

7. Tests:
   Semua dari PROGRESS.md template Phase 1 Sub-task 1.9
   Tambahkan: cache bekerja (panggil 2x, pastikan query DB hanya 1x)

Jalankan: make fresh (seeder baru)
```

---

**QA PROMPT:**
```
Verifikasi:
1. make fresh → 3 plan terseed?
2. make test --filter=Plans → semua pass?
3. Manual: FeatureGateService::check([starter_tenant_id], FeatureKey::GOOGLE_CALENDAR_ENABLED)
   → false?
4. FeatureGateService::getValue([pro_tenant_id], FeatureKey::MONTHLY_LEAD_LIMIT)
   → -1?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.9.
KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: plan system, feature gating, FeatureGateService"
```

---

### SUB-TASK 1.10 — Filament Admin Panel
**Status:** [ ] TODO
**Depends On:** 1.9 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 10 (Filament untuk dashboard)
2. PROGRESS.md
3. app/Modules/Auth/Models/User.php
4. app/Modules/Tenancy/Models/Tenant.php
5. app/Modules/Plans/Models/Plan.php

Konfirmasi: apa PRINSIP 10 tentang Filament vs API?
```

---

**CODING PROMPT:**
```
Setup Filament 3.x dan buat panel admin dasar.

Instalasi Filament sudah dilakukan di sub-task 1.1.
Sekarang konfigurasi dan buat resources.

1. Buat 2 Filament panel:

Panel Superadmin (app/Providers/Filament/SuperadminPanelProvider.php):
  id: 'superadmin'
  path: 'superadmin'
  login: true
  authGuard: 'web'
  authMiddleware: hanya UserRole::SUPERADMIN boleh akses
  colors: ['primary' => Color::Slate]

Panel Tenant (app/Providers/Filament/TenantPanelProvider.php):
  id: 'tenant'
  path: 'app'
  login: true
  authGuard: 'web'
  authMiddleware: hanya UserRole::TENANT_ADMIN boleh akses
  colors: ['primary' => Color::Blue]

2. Superadmin Resources:

TenantResource (list, create, view, edit status):
  Table columns: name, status (badge), contact_email, created_at
  Form fields: name, contact_email, contact_phone, industry
  Actions: resend activation, suspend, activate
  PENTING: Actions call TenantService (bukan duplikasi logic)

PlanResource (list only, readonly):
  Table: name, code, is_active, sort_order

3. Superadmin Widget:
  TenantStatsWidget:
    Total tenants, Active, Trial, Suspended
    Ambil dari DB — jangan hardcode

4. Tenant Panel:
  Dashboard page sederhana (placeholder):
    Tampilkan: "Selamat datang, [nama tenant]!"
    Widget: coming soon placeholder

5. Auth untuk Filament:
  Kedua panel pakai auth Laravel standard (session)
  Buat halaman login untuk masing-masing panel

6. Panel isolation test:
  Jika superadmin coba akses /app → redirect ke /superadmin
  Jika tenant admin coba akses /superadmin → redirect ke /app

7. Tests:
  - GET /superadmin → redirect ke login jika belum auth
  - Login superadmin → akses /superadmin berhasil
  - Login tenant admin → akses /app berhasil
  - Tenant admin akses /superadmin → 403 atau redirect
  - Widget TenantStats menampilkan angka yang benar
```

---

**QA PROMPT:**
```
Verifikasi:
1. make test → semua pass?
2. Buka http://localhost:8080/superadmin → halaman login muncul?
3. Login superadmin → bisa lihat Tenant list?
4. Buka http://localhost:8080/app → halaman login muncul?
5. Login tenant admin → dashboard muncul?
6. Superadmin coba akses /app → di-redirect dengan benar?
7. TenantStatsWidget menampilkan angka yang benar dari DB?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 1.10.
KANBAN-PHASE-1.md: [x] DONE
Git commit: "feat: Filament superadmin dan tenant panel dengan resources dasar"
```

---

### INTEGRATION CHECKPOINT — PHASE 1
**Status:** [ ] TODO

**PROMPT:**
```
Integration Checkpoint akhir Phase 1.

Jalankan semua verifikasi:

1. make fresh → tidak ada error
2. make test → 100% pass (berapa total test?)
3. docker compose ps → semua container running
4. Health checks semua OK:
   [ ] /health
   [ ] /health/db
   [ ] /health/redis
   [ ] /health/queue
   [ ] /health/wa-gateway
5. Full activation flow end-to-end:
   [ ] Superadmin create tenant via API
   [ ] Email masuk ke Mailpit
   [ ] Activate dengan token
   [ ] Tenant admin bisa login
6. Feature gating:
   [ ] Starter tenant: calendar disabled
   [ ] Growth tenant: calendar enabled
7. Tenant isolation:
   [ ] Buat 2 tenant berbeda
   [ ] Login tenant A, akses API → hanya data tenant A
8. Filament:
   [ ] Superadmin panel accessible
   [ ] Tenant panel accessible
   [ ] Cross-access blocked
9. WA Gateway Contract Test:
   [ ] tests/Feature/Contracts/WaGatewayContractTest.php → PASS
   [ ] Verifikasi POST /webhook/inbound format → accepted
   [ ] Verifikasi POST /dispatch format → accepted
   [ ] Verifikasi header X-Internal-Secret → enforced
   [ ] Verifikasi unauthorized request → 403
10. MockLlmAdapter:
    [ ] Unit test yang pakai MockLlmAdapter berjalan tanpa internet
    [ ] Konfirmasi: php artisan test --filter=Unit → tidak ada call ke OpenAI

Exit Gate Checklist:
[ ] php artisan test → 100% PASS
[ ] docker compose up → semua running
[ ] Health endpoints → semua OK
[ ] Full activation flow end-to-end bekerja
[ ] Feature gating correct per plan
[ ] Tenant isolation verified
[ ] Filament kedua panel accessible dan isolated
[ ] WA Gateway contract test PASS
[ ] MockLlmAdapter verified tidak call real API

Jika semua PASS:
1. Update PROGRESS.md: Checkpoint DONE, Gate OPEN
2. Tulis di "CATATAN PENTING ANTAR SUB-TASK" Phase 1:
   - Semua DTO fields frozen
   - Interface contract final
   - WA Gateway contract version
   - Hal penting untuk Phase 2
3. Buat git tag:
   git tag -a v0.2-foundation-complete -m "Foundation complete. Tests: X pass."
4. Lanjut ke KANBAN-PHASE-2.md (generate dari Claude Code)

Generate KANBAN-PHASE-2.md:
"Baca PROGRESS.md, CLAUDE.md, dan semua file yang sudah dibuat di Phase 1.
 Generate KANBAN-PHASE-2.md untuk Knowledge & Settings phase
 dengan format yang sama persis seperti KANBAN-PHASE-1.md.
 Pastikan setiap Context Prompt menyebutkan file yang harus dibaca.
 Sertakan sub-task untuk WeddingDemoSeeder (seed data realistis)."
```
