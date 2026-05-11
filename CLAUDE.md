# CLAUDE.md — WA SaaS AI Sales Agent (Wedding MVP)
# File ini dibaca OTOMATIS oleh Claude Code setiap sesi dimulai.
# JANGAN hapus, rename, atau modifikasi tanpa update seluruh contract.
# Versi: 2.0 — All gaps closed

---

## IDENTITAS PROJECT

```
Nama          : WA SaaS AI Sales Agent
MVP Scope     : Industri Wedding — Jabodetabek
Target Tenant : Semua vendor wedding (foto, catering, dekorasi, venue, WO)
Target Market : Calon pengantin segmen budget (< 50 juta), Jabodetabek
Gaya Bahasa   : Semi-formal — "Kak", bahasa campuran Indonesia informal
Channel MVP   : WhatsApp (utama), arsitektur siap multi-channel
Stack         : Laravel 11 + PostgreSQL + Redis + Filament + Node.js Baileys
Prinsip       : Natural conversation, fully validated, fully logged, zero black box
```

---

## TECH STACK — TIDAK BOLEH DIUBAH TANPA DISKUSI

| Layer | Tech | Versi |
|---|---|---|
| Backend | Laravel | 11 |
| Database | PostgreSQL | 16 |
| Full-text Search | PostgreSQL tsvector | built-in |
| Vector Search | pgvector | latest (Phase 3+) |
| Cache & Queue | Redis | Alpine |
| Queue Monitor | Laravel Horizon | latest |
| Admin Panel | Filament | 3.x |
| WA Gateway | Node.js + Baileys + Express | Node 20 |
| LLM Classifier | GPT-4o-mini | latest |
| LLM Composer | GPT-4o | latest |
| Embedding | text-embedding-3-small | Phase 3+ |
| Container | Docker Compose | latest |
| Reverse Proxy | Nginx | Alpine |
| Email | Resend | latest |
| Object Storage | Cloudflare R2 / S3-compatible | - |

---

## ARSITEKTUR — PRINSIP TIDAK BOLEH DILANGGAR

### PRINSIP 1 — Decision Engine = PHP Murni
```
LLM TIDAK BOLEH membuat keputusan bisnis apapun.
LLM hanya boleh untuk 3 hal:
  1. Classify intent → return JSON {intent, confidence, reason}
  2. Extract entity  → return JSON {entities, corrections}
  3. Compose reply   → return natural language text

Semua keputusan (action, stage, handoff) = PHP rule engine.
```

### PRINSIP 2 — Urutan Pipeline Tidak Boleh Diubah
```
InboundMessage
→ TurnPipelineService
  → SanitizeInput              (hapus prompt injection attempt)
  → IntentClassifierService    (LLM: JSON only, GPT-4o-mini)
  → EntityExtractionService    (LLM: JSON only, GPT-4o-mini)
  → KnowledgeRetrievalService  (DB + tsvector/pgvector)
  → DecisionEngineService      (PHP rules ONLY — no LLM)
  → PolicyValidator
  → GroundingValidator
  → ActionPermissionValidator
  → ModeValidator
  → ResponseComposerService    (LLM: compose reply, GPT-4o)
  → ActionDispatcher
  → DecisionTraceLogger
```

### PRINSIP 3 — Tenant Isolation Wajib
```
Semua model yang berisi data tenant WAJIB:
- Extend TenantBaseModel (bukan BaseModel)
- Punya kolom tenant_id
- Apply TenantScope global scope

TIDAK ADA query yang boleh return data lintas tenant.
Test tenant isolation WAJIB di setiap Integration Checkpoint.
```

### PRINSIP 4 — Interface untuk Semua External Provider
```
LlmClientInterface        → OpenAiAdapter (+ MockLlmAdapter untuk test)
ChannelGatewayInterface   → WhatsAppGatewayAdapter
CalendarProviderInterface → GoogleCalendarAdapter
StorageProviderInterface  → R2StorageAdapter
```

### PRINSIP 5 — UUID untuk Semua Primary Key
```
Semua model WAJIB:
- Extend BaseModel atau TenantBaseModel
- Pakai trait HasUuid
- $incrementing = false
- $keyType = 'string'
```

### PRINSIP 6 — Baileys Terpisah dari Laravel
```
Node.js Baileys gateway = service terpisah.
Laravel TIDAK BOLEH import atau depend on Baileys.
Komunikasi HANYA via HTTP internal dengan header X-Internal-Secret.
```

### PRINSIP 7 — Zero Black Box
```
Setiap AI turn WAJIB menyimpan ke decision_traces:
- Raw inbound message
- Intent result + confidence + reason
- Entity result
- Knowledge yang digunakan (grounding refs)
- Decision yang diambil
- Validator results (pass/fail per validator)
- Prompt yang dikirim ke LLM
- Raw LLM response
- Final reply yang dikirim
- Token usage
- Error jika ada

Tidak boleh ada yang tersembunyi.
```

### PRINSIP 8 — Prompt Versioning
```
Semua prompt WAJIB:
- Tersimpan di PROMPTS.md dengan versi
- Tersimpan di database (prompt_templates table)
- Ada accuracy history setiap perubahan
- Tidak boleh diubah tanpa jalankan accuracy test dulu
- Jika accuracy turun > 5%: ROLLBACK ke versi sebelumnya
```

### PRINSIP 9 — LLM Mock untuk Unit Test
```
Unit test TIDAK BOLEH call real OpenAI API.
Gunakan MockLlmAdapter yang return predefined response.
Real LLM HANYA untuk:
- Accuracy test suite (maksimal 3x per hari)
- Integration test
- POC iteration

Set di .env.testing: LLM_PROVIDER=mock

MockLlmAdapter WAJIB support:
- setNextResponse(string $json)   → return fixed JSON untuk 1 call berikutnya
- setNextResponses(array $jsons)  → queue beberapa response berurutan
- getCallCount(): int             → berapa kali di-call dalam test
- getLastPrompt(): string         → prompt terakhir yang dikirim (untuk assert)
- reset()                         → reset semua state

Setiap unit test WAJIB:
1. setUp() → inject MockLlmAdapter via service container
2. setNextResponse() → tentukan response sebelum call
3. assert terhadap getLastPrompt() untuk verifikasi prompt benar
4. assert terhadap getCallCount() untuk verifikasi tidak double-call
```

### PRINSIP 13 — Contract Test antara Laravel dan WA Gateway
```
WA Gateway (Node.js Baileys) punya API contract dengan Laravel.
Setiap perubahan di salah satu sisi WAJIB update contract test.

Contract yang harus selalu di-test:
1. POST /webhook/inbound (WA → Laravel)
   - Wajib ada: wa_account_id, from_phone, message_type, body, received_at
   - Wajib pakai header: X-Internal-Secret

2. POST /dispatch (Laravel → WA)
   - Wajib ada: wa_account_id, to_phone, message_type, body
   - Response: {success: bool, provider_message_id: string}

3. GET /status/:wa_account_id (Laravel → WA)
   - Response: {status: string, phone: string, connected_at: string}

Test location: tests/Feature/Contracts/WaGatewayContractTest.php
Jika format berubah: update contract test DULU, baru implementasi.
```

### PRINSIP 10 — Filament untuk Dashboard, API untuk Programmatic
```
Filament: semua UI admin (superadmin panel, tenant panel)
API endpoints: untuk WA gateway, mobile app masa depan, webhook

WAJIB: Filament resource dan API controller KEDUANYA
       call Service yang sama — tidak boleh ada logic duplikat.
```

### PRINSIP 11 — Timezone Handling
```
Semua timestamp di database: UTC
Semua display ke user: convert ke timezone tenant
Business hours check: pakai timezone tenant dari tenant_settings
Tanggal event dari customer: simpan dengan timezone tenant

Contoh implementasi:
- Simpan: Carbon::now('UTC') → kolom DB selalu UTC
- Display: $timestamp->setTimezone($tenant->timezone)
- Business hours: Carbon::now($tenant->timezone)->between($open, $close)
- Tanggal event: parse dengan timezone tenant, convert ke UTC untuk disimpan

Timezone default jika tenant belum set: Asia/Jakarta (WIB)
```

### PRINSIP 14 — Concurrent Booking Lock
```
Booking availability check WAJIB pakai pessimistic lock.

// BENAR — pakai lockForUpdate
DB::transaction(function() use ($tenantId, $date) {
    $bookings = Booking::where('tenant_id', $tenantId)
        ->where('event_date', $date)
        ->lockForUpdate()
        ->get();
    // cek availability, lalu insert
});

// SALAH — tanpa lock, race condition
$bookings = Booking::where('event_date', $date)->get();

Race condition scenario:
- Customer A dan B request booking tanggal sama bersamaan
- Tanpa lock: keduanya bisa lolos availability check
- Dengan lock: salah satu harus tunggu
```

### PRINSIP 15 — Billing Period Rules
```
Billing period dimulai dari tanggal subscribe (bukan awal bulan).
Contoh: subscribe 15 Jan → period 15 Jan - 14 Feb

Lead count reset otomatis setiap awal billing period.
Jika tenant upgrade plan di tengah period:
- Lead count TIDAK reset
- Limit langsung berubah ke plan baru
- Billing period tetap sama, tidak mulai ulang

Implementasi: tenant_subscriptions.current_period_start
              + tenant_subscriptions.current_period_end
              Lead count: COUNT dari conversations.created_at dalam period ini
```

### PRINSIP 12 — Input Sanitization
```
Setiap pesan dari WhatsApp WAJIB melalui InputSanitizerService sebelum
masuk ke LLM prompt. Sanitizer WAJIB:
- Detect dan strip prompt injection attempt
  (contoh: "ignore previous instructions", "you are now", dll)
- Truncate pesan yang terlalu panjang (max 2000 karakter)
- Strip karakter null bytes dan control characters
- Log jika ada injection attempt terdeteksi
```

---

## STRUKTUR FOLDER MODULE — WAJIB DIIKUTI

```
laravel-app/
├── app/
│   └── Modules/
│       ├── Shared/
│       │   ├── Models/
│       │   │   ├── BaseModel.php
│       │   │   ├── TenantBaseModel.php
│       │   │   └── Traits/HasUuid.php
│       │   ├── Scopes/TenantScope.php
│       │   ├── Contracts/         ← Semua Interface
│       │   ├── DTOs/              ← Semua DTO
│       │   ├── Enums/             ← Semua Enum
│       │   └── Http/Controllers/HealthController.php
│       ├── Auth/
│       ├── Tenancy/
│       ├── Plans/
│       ├── Knowledge/
│       ├── TenantConfig/
│       ├── AgentCore/
│       │   ├── LLM/
│       │   │   ├── Adapters/OpenAiAdapter.php
│       │   │   ├── Adapters/MockLlmAdapter.php  ← WAJIB untuk test
│       │   │   └── Services/TokenUsageLogger.php
│       │   ├── Security/
│       │   │   └── Services/InputSanitizerService.php
│       │   ├── Classification/
│       │   ├── Extraction/
│       │   ├── Knowledge/
│       │   ├── Decision/
│       │   ├── Validators/
│       │   ├── Composer/
│       │   └── Pipeline/
│       ├── WhatsApp/
│       ├── Conversation/
│       ├── Lead/
│       ├── Handoff/
│       ├── Notification/
│       ├── Invoice/
│       ├── Calendar/
│       ├── Analytics/
│       └── Audit/
├── tests/
│   ├── Unit/
│   ├── Feature/
│   └── conversation-data/
wa-gateway/
docker/
scripts/
poc/                 ← Phase 0 only, tidak masuk production
```

Setiap module memiliki subfolder:
```
Actions/ DTOs/ Enums/ Events/ Jobs/ Models/
Repositories/ Services/ Tests/ routes.php
```

---

## CONTRACT — DTO FIELDS (FROZEN SETELAH PHASE 1)

```
IntentResultDTO:
  intent: string          // slug intent dari intent library
  confidence: float       // 0.0-1.0
  reason: string          // penjelasan classifier
  raw_response: string    // raw LLM output sebelum parsing

EntityResultDTO:
  entities: array                // key-value wedding entity
  corrections: array             // entity yang dikoreksi user
  previous_references: array     // referensi ke conversation lama
  confidence: float
  needs_clarification: array     // entity ambigu yang perlu ditanya ulang
  detected_language: string      // id|en|jv|su|betawi (untuk multi-language handling)

DecisionDTO:
  decision: string
  desired_actions: array
  allowed_actions: array
  blocked_actions: array         // BlockedActionDTO[]
  handoff_required: bool
  handoff_reason: string|null
  handoff_priority: string       // LOW|MEDIUM|HIGH|URGENT
  notification_required: bool
  reply_strategy: string
  active_goal: string
  stage_transition: string|null

BlockedActionDTO:
  action: string
  reason: string
  can_fallback: bool
  fallback_action: string|null

TurnContextDTO:
  tenant: TenantDTO
  conversation: ConversationDTO
  state: ConversationStateDTO
  lead: LeadProfileDTO
  intent: IntentResultDTO
  entities: EntityResultDTO
  knowledge: GroundedKnowledgeDTO
  config: TenantConfigDTO
  inbound_message: InboundMessageDTO
  is_sanitized: bool             // apakah input sudah disanitasi
  injection_detected: bool       // apakah ada injection attempt

TurnResultDTO:
  reply_sent: bool
  actions_dispatched: array
  decision_trace_id: string
  conversation_id: string
  new_state: ConversationStateDTO
  processing_time_ms: int        // untuk monitoring

GroundedKnowledgeDTO:
  structured_data: array
  vector_results: array
  grounding_refs: array          // GroundingRefDTO[]
  search_method: string          // tsvector|pgvector|hybrid

GroundingRefDTO:
  type: string                   // structured|vector
  source: string                 // packages|prices|faqs|knowledge_items
  id: string
  key_data: string

ComposedReplyDTO:
  reply_text: string
  reply_type: string             // text|file|booking_link|mixed
  attachments: array
  grounding_refs: array
  detected_hallucination: bool   // flag jika composer menyebut data yang tidak ada

ValidatorResultDTO:
  policy_result: string          // passed|failed
  grounding_result: string       // passed|failed|partial
  permission_result: string      // passed|blocked_partial|blocked_all
  mode_result: string            // passed|blocked
  final_allowed_actions: array
  final_blocked_actions: array
  warnings: array                // non-blocking issues

InboundMessageDTO:
  wa_account_id: string
  provider_message_id: string
  from_phone: string
  message_type: string           // text|image|audio|document|video
  body: string                   // null untuk non-text
  media_url: string|null         // untuk image/audio/document
  raw_payload: array
  received_at: string            // ISO8601 UTC
```

---

## WEDDING ENTITY SCHEMA

```
customer_name        // nama calon pengantin
event_date           // ISO8601 dengan timezone tenant
event_time_start     // HH:MM format
event_time_end       // HH:MM format
event_type           // akad|resepsi|keduanya
location             // kota/venue/area
guest_count          // integer
budget_min           // IDR integer
budget_max           // IDR integer
package_interest     // nama paket yang diminati (raw dari customer)
package_slug         // slug matched di DB (null jika tidak match)
objection            // price|trust|timing|competitor|need_discussion
booking_intent_signal // boolean
payment_topic        // dp|pelunasan|cicilan|refund
invoice_reference    // string|latest
detected_language    // id|en|jv|su|mixed
```

---

## ENUM REFERENCE LENGKAP

```
UserRole:            SUPERADMIN, TENANT_ADMIN
TenantStatus:        TRIAL, ACTIVE, EXPIRED, SUSPENDED
FeatureKey:          MAX_WA_AGENTS, MONTHLY_LEAD_LIMIT,
                     GOOGLE_CALENDAR_ENABLED, FOLLOW_UP_AUTOMATION,
                     ANALYTICS_ADVANCED, MULTI_CHANNEL
WaAccountStatus:     DISCONNECTED, QR_PENDING, CONNECTING,
                     CONNECTED, RECONNECTING, FAILED, BANNED_OR_RESTRICTED
ConversationStage:   NEW_LEAD, EXPLORATION, QUALIFICATION,
                     RECOMMENDATION, CONSIDERATION, BOOKING,
                     WAITING_BOOKING, CLOSED, INVOICE_PHASE,
                     POST_INVOICE_LIMITED, HANDOFF, PAUSED_ADMIN
AgentMode:           ACTIVE, PAUSED, HANDOFF, LIMITED
MemoryMode:          ACTIVE, DORMANT
LeadTemperature:     COLD, WARM, HOT
HandoffPriority:     LOW, MEDIUM, HIGH, URGENT
HandoffStatus:       PENDING, IN_PROGRESS, RESOLVED
TenantTone:          FORMAL, SEMI_FORMAL, FRIENDLY, CASUAL
PolicyKey:           PRICELIST_MODE, PRICELIST_MIN_REQUIREMENT,
                     LEAD_LIMIT_FALLBACK, AFTER_HOURS_BEHAVIOR,
                     INVOICE_MAX_RESEND, CONCURRENT_BOOKING_LOCK
AssetType:           PRICELIST, BROCHURE, PORTFOLIO, OTHER
NotificationType:    HANDOFF_REQUIRED, MESSAGE_WHILE_PAUSED,
                     WA_DISCONNECTED, CALENDAR_ERROR,
                     INVOICE_ACTION, BOOKING_ACTION,
                     INJECTION_ATTEMPT_DETECTED
MessageType:         TEXT, IMAGE, AUDIO, DOCUMENT, VIDEO, STICKER
```

---

## NAMING CONVENTION

```
Service      : IntentClassifierService
Repository   : ConversationRepository
DTO          : IntentResultDTO
Enum         : AgentMode
Interface    : LlmClientInterface
Adapter      : OpenAiAdapter, MockLlmAdapter
Job          : ProcessInboundMessageJob
Event        : ConversationHandedOff
Action       : SendPricelistAction
Controller   : HealthController
DB table     : tenant_subscriptions (snake_case plural)
DB column    : created_at, tenant_id (snake_case)
Test class   : IntentClassifierServiceTest
```

---

## EDGE CASES YANG WAJIB DITANGANI

### Bahasa & Input
```
1. Typo ekstrem    : "kak mau tnya sol pket" → ekstrak intent tetap
2. Bahasa campuran : "kak gimana dong ya" → handle natural
3. Singkatan       : "tgl", "jt", "utk", "yg" → normalize
4. Angka informal  : "30an", "50rb", "2jt" → convert ke integer IDR
5. Tanggal ambigu  : "minggu depan", "bulan april" → flag needs_clarification
6. Multi bahasa    : detect jika ada English, Jawa, Sunda → handle gracefully
7. Pesan kosong    : hanya emoji atau sticker → handle sebagai unclear_message
8. Voice note      : message_type=audio → fallback: "Maaf Kak, kami belum bisa
                     baca pesan suara. Bisa diketik ya Kak? 🙏"
9. Image/document  : → acknowledge: "Terima kasih Kak sudah kirim [foto/file].
                     Untuk info lengkapnya, boleh ceritakan via chat ya Kak 😊"
10. Pesan sangat panjang : > 2000 karakter → truncate + flag
11. Bahasa Jawa    : "pinten regine pakete?" → handle seperti Indonesian, extract entities
12. Bahasa Sunda   : "sabaraha hargana?" → idem
13. Full English   : "how much is the wedding package?" → reply in English, same flow
14. Customer marah : "ini gimana sih lambat banget!" → de-escalate response, jika
                     ada kata kasar 2x dalam satu conversation → auto handoff URGENT
15. Ancaman/SARA   : → immediate handoff URGENT + NotificationType::HANDOFF_REQUIRED
```

### Concurrent & Race Condition
```
1. Multi pesan cepat    : Redis lock per conversation_id
2. Concurrent booking   : Pessimistic lock saat cek availability
3. Duplicate message    : Redis idempotency key per provider_message_id, TTL 24 jam
4. Session race         : Queue per wa_account_id, tidak parallel
```

### LLM Failure
```
1. LLM timeout          : Fallback response + retry queue
2. LLM invalid JSON     : JsonRepairGuard → jika gagal: fallback response
3. LLM rate limit       : Exponential backoff + notify admin
4. OpenAI down          : Queue message, auto-retry setelah 5 menit
5. Fallback response    : "Maaf Kak, ada kendala teknis. Tim kami akan segera membalas 🙏"
```

### Conversation Panjang & Context Management
```
1. > 50 pesan dalam 1 conversation → aktivasi summarization strategy:
   - Ambil 10 pesan terakhir sebagai "active context"
   - Summary dari pesan 1-40 disimpan di conversation.context_summary
   - Inject summary ke TurnContext sebagai "previous context"
   - Token limit: max 4000 token untuk history context

2. Customer ganti nomor HP:
   - Lead tetap separate (keamanan — tidak auto-merge)
   - Tenant admin bisa manual merge dari Filament

3. Conversation > 30 hari tanpa activity:
   - Set memory_mode = DORMANT
   - Saat customer kembali: greet ulang, jangan assume masih ingat semua detail
```

### Load & Concurrency
```
1. 10 pesan bersamaan dari tenant berbeda:
   - Queue per wa_account_id (tidak parallel dalam 1 account)
   - Antar account: parallel (Laravel Horizon workers)
   - Default: 3 workers minimum di production

2. Docker/Redis restart:
   - Pesan yang sedang diproses: akan retry otomatis (Laravel queue)
   - Session Baileys: perlu scan QR ulang (normal, documented di RECOVERY.md)
   - Redis queue: pesan tidak hilang jika pakai Redis persistence (AOF)

3. Redis persistence WAJIB di production docker-compose:
   redis:
     command: redis-server --appendonly yes --appendfsync everysec
```

### Security
```
1. Prompt injection     : InputSanitizerService → strip + log + notify
2. Brute force token    : Rate limit 5x per IP per jam
3. File upload malware  : Validasi mime type server-side, size max 10MB
4. Data bocor antar tenant: TenantScope wajib di semua query
5. API key di log       : Laravel log tidak boleh catat raw payload yang mengandung key
6. PII di log           : Nomor HP di-mask di log → +62***xxx, bukan full number
7. Injection patterns yang WAJIB di InputSanitizerService:
   - "ignore previous instructions"
   - "you are now"
   - "disregard"
   - "forget everything"
   - "new instructions:"
   - "system prompt"
   - "act as"
   - "pretend you are"
   - "[INST]", "<<SYS>>"
   - "jangan ikuti instruksi sebelumnya"
   - "abaikan instruksi"
   → Jika terdeteksi: strip bagian injection, log, emit NotificationType::INJECTION_ATTEMPT_DETECTED
   → Lanjutkan pipeline dengan pesan yang sudah disanitasi
   → Jangan langsung block customer (bisa false positive)
```

---

## RULES WAJIB UNTUK CLAUDE CODE

```
SEBELUM CODING:
1. Baca CLAUDE.md ini dari awal
2. Baca PROGRESS.md untuk tahu status terkini
3. Baca semua file yang disebutkan di CONTEXT PROMPT
4. Konfirmasi pemahaman sebelum mulai

SAAT CODING:
5. Buat HANYA file yang disebutkan di Coding Prompt
6. Jangan tambah file atau fitur yang tidak diminta
7. Semua model WAJIB extend BaseModel atau TenantBaseModel
8. Semua query tenant WAJIB ada tenant_id filter
9. Unit test WAJIB pakai MockLlmAdapter, bukan real API
10. Setiap file baru: verifikasi dengan php -l [file]

SETELAH CODING:
11. Jalankan: php artisan test → harus 100% pass
12. Verifikasi setiap file yang dibuat dengan cat/read
13. Update PROGRESS.md: status, files created, methods exposed
14. Update File Registry di PROGRESS.md
15. Centang sub-task di KANBAN-PHASE-X.md
16. Buat git commit dengan message descriptive
17. Laporkan: apa yang dibuat, apa yang di-expose, ada issue tidak

LARANGAN MUTLAK:
❌ Jangan hardcode API key, password, atau secret
❌ Jangan skip test karena "sudah yakin benar"
❌ Jangan implement sub-task berikutnya dalam sesi yang sama
❌ Jangan overwrite file yang sudah ada tanpa baca dulu
❌ Jangan buat hallucination — jika tidak tahu, tanya
❌ Jangan ubah DTO fields tanpa update CLAUDE.md dulu
❌ Jangan call real OpenAI API dari unit test
```
