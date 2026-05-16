# KANBAN-PHASE-5.md — Pricelist, Booking, Invoice, Calendar, Follow-up
# PRE-REQUISITE: Phase 4 gate harus OPEN di PROGRESS.md
# Target: Pricelist flow real, Booking + concurrent lock, Invoice generation,
#         Google Calendar sync, Follow-up automation
# Exit Gate: php artisan test 100% pass; pesan WA → reply mencantumkan paket/harga real;
#            booking tercipta + Google Calendar event sinkron; invoice ter-issue & dikirim;
#            follow-up job ter-schedule.

---

## CEK SEBELUM MULAI

```
Buka PROGRESS.md, pastikan:
[ ] Integration Checkpoint Phase 4 → Gate: OPEN
[ ] Git tag v0.5-whatsapp-complete sudah ada
[ ] 413 tests passing dari Phase 4

Jika belum: selesaikan Phase 4 dulu.

Yang sudah ada dari Phase 0-4 (JANGAN dibuat ulang):
[x] Package, PackagePrice, Asset, KnowledgeItem, Faq models (Phase 2)
[x] PackageResolver, KnowledgeRetriever (Phase 2)
[x] TurnPipelineService, ActionDispatcher (Phase 3)
[x] DecisionEngineService — sudah handle stage transition ke BOOKING/INVOICE_PHASE
[x] HandoffService, NotificationService (Phase 4)
[x] WhatsAppGatewayAdapter — bisa sendText, sendFile (Phase 4)
[x] WaAccountRepository::getActiveForTenant() — untuk dispatch outbound (Phase 4)
[x] Enum ConversationStage: BOOKING, WAITING_BOOKING, INVOICE_PHASE, POST_INVOICE_LIMITED
[x] Enum NotificationType: BOOKING_ACTION, INVOICE_ACTION, CALENDAR_ERROR
[x] Enum PolicyKey: PRICELIST_MODE, PRICELIST_MIN_REQUIREMENT,
                    INVOICE_MAX_RESEND, CONCURRENT_BOOKING_LOCK
[x] Enum AssetType: PRICELIST, BROCHURE, PORTFOLIO, OTHER
[x] Enum FeatureKey: GOOGLE_CALENDAR_ENABLED, FOLLOW_UP_AUTOMATION
[x] PRINSIP 14 — Concurrent Booking Lock (CLAUDE.md)
[x] PRINSIP 4 — CalendarProviderInterface contract sudah documented
```

---

### SUB-TASK 5.1 — PricelistService + SendPricelistAction
**Status:** [x] DONE — 2026-05-15
**Depends On:** Phase 4 gate OPEN
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 1 (LLM tidak putuskan bisnis), PRINSIP 7 (zero black box)
2. CLAUDE.md — Enum AssetType (PRICELIST), PolicyKey (PRICELIST_MODE, PRICELIST_MIN_REQUIREMENT)
3. app/Modules/Knowledge/Models/Asset.php — type=PRICELIST sudah didukung
4. app/Modules/Knowledge/Models/Package.php — relation prices()
5. app/Modules/AgentCore/Pipeline/Services/ActionDispatcher.php — pola match($action)
6. app/Modules/Shared/Contracts/ChannelGatewayInterface.php — sendFile signature
7. app/Modules/AgentCore/Decision/Services/DecisionEngineService.php — desired_actions
   (cari di mana 'send_pricelist' di-emit; jika belum: tambahkan rule)

PRICELIST_MODE policy values:
- 'pdf'      → kirim file Asset type=PRICELIST
- 'text'     → composer generate teks dari Package + PackagePrice
- 'hybrid'   → text dulu, lalu PDF sebagai lampiran
- 'disabled' → blocked_action: send_pricelist (cannot fallback)

PRICELIST_MIN_REQUIREMENT policy values:
- 'never'                → selalu boleh
- 'after_qualification'  → hanya jika stage >= QUALIFICATION
- 'after_event_date'     → hanya jika entity.event_date ter-extract

Konfirmasi:
- Kenapa pricelist butuh policy? (sebagian tenant tidak mau bagi harga sembarangan)
- Apa beda PRICELIST_MIN_REQUIREMENT vs intent? (intent=ask_price boleh ditolak
  oleh policy → emit blocked_action, composer reply dengan minta info lebih dulu)
```

---

**CODING PROMPT:**
```
Buat PricelistService + SendPricelistAction.

1. PricelistService.php (app/Modules/Knowledge/Services/):
   Constructor: inject TenantPolicyService, PackageResolver

   canSendPricelist(TurnContextDTO $context): array
     → Return ['allowed'=>bool, 'reason'=>string|null, 'fallback'=>string|null]
     → Cek PRICELIST_MODE policy
     → Cek PRICELIST_MIN_REQUIREMENT vs context.state.stage + entity_cache
     → Jika disabled: allowed=false, fallback='manual_quote_request'

   getPricelistAsset(string $tenantId): ?Asset
     → Asset::query()->active()->byType(AssetType::PRICELIST)->latest()->first()

   buildTextPricelist(string $tenantId, array $filterCategory = []): string
     → Ambil Package::active() + activePrices()
     → Format teks rapi: "📋 Paket Kami:\n\n1. Silver — 15jt\n   • ..."
     → Return string siap kirim

   getMode(string $tenantId): string
     → Return value PolicyKey::PRICELIST_MODE

2. Update DecisionEngineService.php:
   Pada determineDesiredActions(): jika intent=ask_price OR ask_package_list:
     → Tambahkan 'send_pricelist' ke desired_actions
     → Tidak override yang sudah ada

   Pada method baru applyPricelistPolicy(TurnContextDTO, array $actions):
     → Panggil PricelistService::canSendPricelist
     → Jika tidak allowed: pindahkan 'send_pricelist' ke blocked_actions
       dengan BlockedActionDTO {reason, can_fallback, fallback_action}

3. Update ActionDispatcher.php:
   Tambah case di match():
     'send_pricelist' => $this->sendPricelist($context, $reply)

   sendPricelist(TurnContextDTO $ctx, ComposedReplyDTO $reply): void
     → Mode='pdf'   → gateway->sendFile($asset.file_url, caption=reply.text)
     → Mode='text'  → sudah dikirim sebagai reply text biasa (no-op tambahan)
     → Mode='hybrid'→ text dulu (sendReply), lalu sendFile sebagai lampiran
     → Jika asset null saat mode pdf/hybrid → log warning, fallback ke text

4. Update ResponseComposerService grounding:
   Jika 'send_pricelist' di allowed_actions dan mode=text/hybrid:
     → Inject buildTextPricelist() ke context grounded knowledge
     → Composer wajib pakai data ini, tidak boleh halusinasi harga

5. Update ChannelGatewayInterface (jika belum):
   sendFile(string $waAccountId, string $toPhone, string $fileUrl, string $caption=''): bool

6. Update WhatsAppGatewayAdapter:
   Implementasi sendFile → POST /dispatch dengan message_type='document',
   tambahkan field 'media_url' dan 'caption'.

7. Update wa-gateway/index.js & sessionManager.js:
   Handle dispatch dengan message_type='document':
   sock.sendMessage(jid, { document: { url }, mimetype, caption })

8. Tests (app/Modules/Knowledge/Tests/PricelistServiceTest.php):
   - canSendPricelist mode=disabled → allowed=false
   - canSendPricelist requirement=after_qualification + stage=NEW_LEAD → allowed=false
   - canSendPricelist requirement=after_qualification + stage=QUALIFICATION → allowed=true
   - getPricelistAsset → return asset PRICELIST active
   - buildTextPricelist → mengandung nama paket + harga aktif
   - Tenant isolation: asset tenant A tidak diakses oleh tenant B

9. Tests (tests/Feature/Pipeline/PricelistFlowTest.php):
   - intent=ask_price + mode=pdf → ActionDispatcher::sendPricelist dipanggil,
     gateway->sendFile mendapat Asset.file_url (Http::fake assertSent)
   - intent=ask_price + mode=disabled → blocked_action send_pricelist,
     composer reply dengan strategi 'manual_quote_request'
   - intent=ask_package_list + mode=text → reply text mengandung nama paket
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter='PricelistServiceTest|PricelistFlowTest' → semua pass?
2. Tinker:
   >>> $svc = app(PricelistService::class)
   >>> $svc->buildTextPricelist($tenantId) // mengandung 'Silver' atau paket lain?
3. Decision trace setelah intent=ask_price:
   - allowed_actions mengandung 'send_pricelist' (jika policy allow)
   - blocked_actions mengandung BlockedActionDTO jika disabled
4. Http::fake assertSent: POST /dispatch dengan message_type='document' (mode=pdf)
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.1.
Files: PricelistService.php, DecisionEngineService.php (updated),
       ActionDispatcher.php (updated), PricelistServiceTest, PricelistFlowTest,
       wa-gateway sessionManager.js (sendMessage document support)
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: PricelistService — PDF/text/hybrid pricelist dispatch, policy gating"
```

---

### SUB-TASK 5.2 — Booking Model + Migration + Repository
**Status:** [x] DONE — 2026-05-15
**Depends On:** 5.1 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 3 (Tenant Isolation), PRINSIP 5 (UUID), PRINSIP 14 (Concurrent Booking Lock)
2. CLAUDE.md — Enum ConversationStage: BOOKING, WAITING_BOOKING
3. CLAUDE.md — Wedding Entity Schema (event_date, event_time_start, event_time_end,
   event_type, location, guest_count, package_slug)
4. app/Modules/Conversation/Models/Conversation.php — relation conversations
5. app/Modules/Lead/Models/Lead.php — relation leads
6. app/Modules/Knowledge/Models/Package.php

Konfirmasi:
- Apa beda Booking dengan Conversation? (Conversation=chat, Booking=transaksi)
- Status awal booking? (DRAFT saat dibuat dari pipeline, CONFIRMED saat lead konfirmasi)
- Kenapa booking butuh tenant_id meski bisa derived via conversation? (tenant isolation
  tegas + indexing langsung tanpa join)
```

---

**CODING PROMPT:**
```
Buat Booking model + repository.

1. Enum BookingStatus (app/Modules/Shared/Enums/):
   DRAFT       — baru dibuat, menunggu konfirmasi customer
   CONFIRMED   — customer sudah konfirmasi
   AWAITING_DP — menunggu DP / invoice issued
   PAID        — DP/pelunasan diterima
   COMPLETED   — event sudah berlangsung
   CANCELLED   — dibatalkan
   EXPIRED     — DRAFT > 7 hari tanpa konfirmasi
   Method: label()

2. Migration create_bookings_table (2026_05_12_700001):
   id uuid PK
   tenant_id uuid FK tenants (cascade)
   conversation_id uuid nullable FK conversations
   lead_id uuid nullable FK leads
   package_id uuid nullable FK packages
   booking_code varchar(20) UNIQUE          — BKG-YYYYMM-XXXX format
   status varchar(20) default 'draft'        — BookingStatus
   event_date date                           — wajib
   event_time_start time nullable
   event_time_end time nullable
   event_type varchar(50) nullable           — akad|resepsi|keduanya
   location varchar(255) nullable
   guest_count int nullable
   customer_name varchar(255) nullable
   customer_phone varchar(20) nullable
   total_amount bigint default 0             — IDR cents (atau full IDR? full IDR utuh)
   dp_amount bigint default 0
   notes text nullable
   metadata jsonb default '{}'
   calendar_event_id varchar(255) nullable   — Google Calendar event id
   confirmed_at timestamp nullable
   cancelled_at timestamp nullable
   created_at, updated_at
   INDEX: [tenant_id, status]
   INDEX: [tenant_id, event_date]
   UNIQUE: [tenant_id, event_date, event_type] WHERE status IN ('confirmed','awaiting_dp','paid')
     — 1 event_type per tenant per tanggal (concurrent lock guard)

3. Booking.php model (extend TenantBaseModel):
   Cast: status → BookingStatus, event_date → date, metadata → array,
         total_amount → int, dp_amount → int
   Relations:
     conversation(): belongsTo(Conversation)
     lead(): belongsTo(Lead)
     package(): belongsTo(Package)
     invoices(): hasMany(Invoice) — sub-task 5.4
   Methods:
     isActive(): bool → status NOT IN [CANCELLED, EXPIRED, COMPLETED]
     markConfirmed(): void → status=CONFIRMED, confirmed_at=now()
     markCancelled(string $reason=''): void → status=CANCELLED, cancelled_at=now()
     generateBookingCode(): string → format BKG-YYYYMM-XXXX (XXXX = sequence per month per tenant)

4. BookingRepository (app/Modules/Booking/Repositories/):
   findById(string $id): ?Booking
   findByCode(string $code): ?Booking
   findActiveByTenantAndDate(string $tenantId, Carbon $date): Collection
   findUpcomingByTenant(string $tenantId, int $days=30): Collection
   countByStatus(string $tenantId, BookingStatus $status): int
   create(array $data): Booking — auto generate booking_code

5. BookingServiceProvider.php (app/Modules/Booking/Providers/):
   Register BookingRepository as singleton.
   Register di config/app.php providers.

6. Tests (app/Modules/Booking/Tests/BookingTest.php):
   - create booking → status=DRAFT, booking_code unique format BKG-202605-NNNN
   - markConfirmed → status=CONFIRMED, confirmed_at terisi
   - markCancelled → status=CANCELLED, cancelled_at terisi
   - findActiveByTenantAndDate: tidak include CANCELLED/EXPIRED
   - UNIQUE constraint: insert 2 booking sama (tenant, date, event_type=resepsi)
     status=CONFIRMED → 2nd throws QueryException
   - Tenant isolation: booking tenant A tidak muncul di repo tenant B
   - booking_code sequence: dua booking di bulan sama → kode XXXX berbeda
```

---

**QA PROMPT:**
```
1. php artisan migrate → bookings table ada?
2. php artisan test --filter='BookingTest' → semua pass?
3. Tinker:
   >>> $b = app(BookingRepository::class)->create([
   ...     'tenant_id'=>$tid,'event_date'=>'2026-09-01','event_type'=>'resepsi'
   ... ])
   >>> $b->booking_code // match /^BKG-\d{6}-\d{4}$/ ?
4. Insert duplicate (same tenant+date+event_type, status=CONFIRMED) → DB error?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.2.
Files: BookingStatus enum, Booking model, migration, BookingRepository,
       BookingServiceProvider, BookingTest
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: Booking model, migration, repository — booking foundation"
```

---

### SUB-TASK 5.3 — BookingService + Concurrent Lock + CreateBookingAction
**Status:** [x] DONE — 2026-05-16
**Depends On:** 5.2 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 14 (Concurrent Booking Lock dengan lockForUpdate)
2. CLAUDE.md — Enum NotificationType::BOOKING_ACTION
3. CLAUDE.md — PolicyKey::CONCURRENT_BOOKING_LOCK
4. app/Modules/Booking/Repositories/BookingRepository.php (dari 5.2)
5. app/Modules/Conversation/Models/Conversation.php — stage transition ke BOOKING

Race condition scenario yang HARUS ditangani:
- Customer A dan B request booking tanggal 1 Sep 2026 'resepsi' bersamaan
- Tanpa lockForUpdate: keduanya bisa lolos availability check → duplicate
- Dengan lockForUpdate: pessimistic lock pada read-time, sequential insert

Konfirmasi:
- Apa output ketika tanggal sudah dibooking? (alternative dates suggestion)
- Kapan booking auto-EXPIRED? (DRAFT > 7 hari, via scheduler nanti)
```

---

**CODING PROMPT:**
```
Buat BookingService dan integrasi pipeline.

1. BookingService.php (app/Modules/Booking/Services/):
   Constructor: inject BookingRepository, NotificationService

   checkAvailability(string $tenantId, Carbon $date, ?string $eventType=null): bool
     → DB::transaction(fn() =>
         Booking::withoutGlobalScopes()
           ->where('tenant_id', $tenantId)
           ->where('event_date', $date->toDateString())
           ->when($eventType, fn($q,$e) => $q->where('event_type',$e))
           ->whereIn('status', ['confirmed','awaiting_dp','paid'])
           ->lockForUpdate()
           ->doesntExist()
       )

   createDraft(TurnContextDTO $context): ?Booking
     → DB::transaction:
       1. checkAvailability dalam lock
       2. Jika tersedia: BookingRepository::create dengan entity_cache + conversation_id
       3. Jika tidak: return null
     → Emit NotificationType::BOOKING_ACTION (BOOKING_DRAFTED)
     → Update conversation.stage = WAITING_BOOKING

   confirm(Booking $booking): void
     → DB::transaction → re-check availability (defensive)
     → booking.markConfirmed()
     → Update conversation.stage = BOOKING
     → Notifikasi admin BOOKING_ACTION (CONFIRMED)

   cancel(Booking $booking, string $reason): void
     → booking.markCancelled($reason)
     → Notifikasi admin

   suggestAlternatives(string $tenantId, Carbon $date, int $range=14): array
     → Cari tanggal terdekat (±$range hari) yang masih available
     → Return array<['date'=>Carbon, 'distance_days'=>int]>

2. Action 'create_booking' di ActionDispatcher:
   case 'create_booking' => $this->createBooking($context, $decision)

   createBooking(): void
     → Validasi entity_cache: event_date wajib ada
     → BookingService::createDraft($context)
     → Jika null: emit blocked_action dengan reason='date_unavailable'

3. Update DecisionEngineService:
   Pada determineDesiredActions(): jika intent IN ['confirm_booking','request_booking']
     AND entity_cache.event_date NOT NULL:
     → Tambahkan 'create_booking' ke desired_actions
     → stage_transition = WAITING_BOOKING

4. Update ResponseComposerService:
   Saat decision.desired_actions contain 'create_booking' yang ALLOWED:
     → Composer mention kode booking baru (dari context.metadata.last_booking_code)
   Saat blocked_actions contain 'create_booking' (reason=date_unavailable):
     → Composer offer alternatives dari suggestAlternatives()

5. Tests (app/Modules/Booking/Tests/BookingServiceTest.php):
   - checkAvailability date kosong → true
   - checkAvailability date sudah ada CONFIRMED booking → false
   - createDraft → Booking tersimpan DRAFT, conversation.stage=WAITING_BOOKING
   - createDraft + tidak tersedia → return null
   - confirm → status=CONFIRMED, conversation.stage=BOOKING, notification dikirim
   - cancel → status=CANCELLED
   - suggestAlternatives → return list tanggal ±14 hari yang available
   - Concurrent race (manual: jalankan 2 createDraft di transaksi parallel — pakai
     DB::beginTransaction & DB::pretend kalau perlu, atau sequential dengan assert
     yang kedua return null)
   - Tenant isolation: tenant A bookings tidak muncul untuk tenant B

6. Tests (tests/Feature/Pipeline/BookingFlowTest.php):
   - intent=request_booking + entity event_date → pipeline buat Booking DRAFT
   - conversation.stage berubah WAITING_BOOKING
   - intent=request_booking tanpa event_date → tidak ada booking, ada
     needs_clarification entity
   - intent=confirm_booking + ada booking DRAFT → booking.status=CONFIRMED
```

---

**QA PROMPT:**
```
1. php artisan test --filter='BookingServiceTest|BookingFlowTest' → pass?
2. Manual race test (opsional):
   - Buka 2 tinker session, run createDraft same date bersamaan
   - Salah satu return null
3. Decision trace: intent=request_booking → desired_actions contain 'create_booking'
4. Notification: BOOKING_ACTION tersimpan setelah createDraft
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.3.
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: BookingService — concurrent lock, create/confirm/cancel, pipeline action"
```

---

### SUB-TASK 5.4 — Invoice Model + Migration + InvoiceService
**Status:** [x] DONE — 2026-05-16
**Depends On:** 5.3 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 5 (UUID), PRINSIP 3 (Tenant Isolation)
2. CLAUDE.md — Enum ConversationStage::INVOICE_PHASE, POST_INVOICE_LIMITED
3. CLAUDE.md — Enum NotificationType::INVOICE_ACTION
4. CLAUDE.md — PolicyKey::INVOICE_MAX_RESEND
5. app/Modules/Booking/Models/Booking.php — hasMany Invoice

Konfirmasi:
- Invoice type: DP (uang muka) vs PELUNASAN — beda nominal & alur
- Generate PDF di Phase 5? (MVP: simpan sebagai text/markdown, generate PDF Phase 6)
- payment_proof_url: customer upload (Phase 6) atau admin verifikasi manual? (manual MVP)
```

---

**CODING PROMPT:**
```
Buat Invoice model + service.

1. Enum InvoiceStatus (app/Modules/Shared/Enums/):
   ISSUED      — invoice dibuat, dikirim ke customer
   SENT        — sudah dikirim via WA
   PAID        — admin tandai paid
   OVERDUE     — > due_date belum paid
   CANCELLED   — dibatalkan (booking cancel)
   Method: label()

2. Enum InvoiceType:
   DP          — uang muka
   PELUNASAN   — final payment
   Method: label()

3. Migration create_invoices_table (2026_05_12_800001):
   id uuid PK
   tenant_id uuid FK
   booking_id uuid FK bookings (cascade)
   invoice_number varchar(30) UNIQUE        — INV-YYYYMM-XXXX
   type varchar(20)                          — InvoiceType
   status varchar(20) default 'issued'       — InvoiceStatus
   amount bigint                             — IDR full (tanpa cents)
   due_date date
   notes text nullable
   sent_count int default 0                  — counter resend
   sent_at timestamp nullable
   paid_at timestamp nullable
   payment_proof_url varchar(500) nullable
   metadata jsonb default '{}'
   created_at, updated_at
   INDEX: [tenant_id, status]
   INDEX: [booking_id]
   INDEX: [tenant_id, due_date, status]      — untuk overdue scan

4. Invoice.php model (extend TenantBaseModel):
   Cast: status → InvoiceStatus, type → InvoiceType, due_date → date,
         metadata → array, amount → int
   Relations:
     booking(): belongsTo(Booking)
     tenant(): belongsTo(Tenant)
   Methods:
     isPaid(): bool
     isOverdue(): bool → !paid && now() > due_date
     markSent(): void → sent_count++, sent_at=now(), status=SENT
     markPaid(string $proofUrl=''): void
     canResend(): bool → sent_count < PolicyKey::INVOICE_MAX_RESEND
     generateInvoiceNumber(): string → INV-YYYYMM-XXXX

5. InvoiceRepository (app/Modules/Invoice/Repositories/):
   findById, findByNumber, findByBooking,
   findOverdueByTenant(string $tenantId): Collection,
   countByStatus, create

6. InvoiceService.php (app/Modules/Invoice/Services/):
   Constructor: inject InvoiceRepository, NotificationService,
                ChannelGatewayInterface, WaAccountRepository

   issue(Booking $booking, InvoiceType $type, int $amount, Carbon $dueDate): Invoice
     → Create Invoice status=ISSUED
     → Update booking.status = AWAITING_DP (jika type=DP)
     → Update conversation.stage = INVOICE_PHASE
     → Notifikasi admin INVOICE_ACTION

   send(Invoice $invoice): bool
     → Cek canResend(); jika false: return false
     → Format pesan WA: invoice number, amount, due, bank info (dari tenant_settings)
     → gateway->sendText via WaAccount aktif tenant
     → invoice.markSent()
     → Update conversation.stage = POST_INVOICE_LIMITED (lock AI agar tidak terus-terusan jawab)

   markPaid(Invoice $invoice, string $proofUrl=''): void
     → invoice.markPaid($proofUrl)
     → Jika type=DP: booking.status=PAID (atau AWAITING jika ada pelunasan)
     → Update conversation.stage = BOOKING (kembali normal)
     → Notifikasi admin

   markCancelled(Invoice $invoice): void
     → invoice.status=CANCELLED

   getOverdueByTenant(string $tenantId): Collection
     → Repository::findOverdueByTenant
     → Auto-mark status=OVERDUE jika isOverdue() && status=SENT

7. Action 'send_invoice' di ActionDispatcher:
   Tidak dipicu otomatis dari AI; dipicu manual via Filament action (5.8)
   atau via tenant admin button.

8. Tests (app/Modules/Invoice/Tests/InvoiceServiceTest.php):
   - issue → Invoice tersimpan ISSUED, booking.status=AWAITING_DP, notif kirim
   - send → invoice.status=SENT, sent_count=1, gateway dipanggil (Http::fake)
   - send 2x dengan INVOICE_MAX_RESEND=2 → success
   - send 3x → canResend false, return false
   - markPaid → invoice.status=PAID, booking.status=PAID
   - getOverdueByTenant → invoice yang due_date lewat & SENT → status=OVERDUE
   - Tenant isolation
```

---

**QA PROMPT:**
```
1. php artisan migrate → invoices table ada?
2. php artisan test --filter='InvoiceServiceTest' → pass?
3. Tinker:
   >>> $inv = app(InvoiceService::class)->issue($booking, InvoiceType::DP, 5000000, today()->addDays(7))
   >>> $inv->invoice_number  // INV-202605-XXXX?
   >>> app(InvoiceService::class)->send($inv) // true?
   >>> $inv->fresh()->sent_count // 1?
4. Notification INVOICE_ACTION tersimpan?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.4.
Files: InvoiceStatus, InvoiceType enums, Invoice model, migration,
       InvoiceRepository, InvoiceService, InvoiceServiceTest
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: Invoice model + service — issue, send, mark paid, overdue scan"
```

---

### SUB-TASK 5.5 — CalendarProviderInterface + GoogleCalendarAdapter (Stub Safe)
**Status:** [x] DONE — 2026-05-16
**Depends On:** 5.4 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 4 (Interface untuk Calendar)
2. CLAUDE.md — Enum FeatureKey::GOOGLE_CALENDAR_ENABLED
3. CLAUDE.md — Enum NotificationType::CALENDAR_ERROR
4. config/services.php — google credentials (siapkan slot, tidak perlu real credentials di Phase 5)

Pendekatan Phase 5 (MVP):
- CalendarProviderInterface didefinisikan
- GoogleCalendarAdapter MOCK SAFE — tidak panggil real Google API; pakai HTTP call
  ke endpoint yang diset di config; bisa Http::fake di test
- Real Google OAuth flow → Phase 6

Konfirmasi:
- Kenapa pakai HTTP-style adapter daripada Google SDK langsung?
  (PRINSIP 4: bisa swap, dan Phase 5 tidak butuh real call)
- Jika feature flag GOOGLE_CALENDAR_ENABLED=false → no-op, return event_id null
```

---

**CODING PROMPT:**
```
Buat CalendarProviderInterface + adapter.

1. CalendarEventDTO (app/Modules/Shared/DTOs/):
   id: string|null
   tenant_id: string
   title: string
   description: string
   start_at: string (ISO8601 UTC)
   end_at: string (ISO8601 UTC)
   location: string|null
   attendees: array (email[])
   metadata: array

2. CalendarProviderInterface (app/Modules/Shared/Contracts/):
   createEvent(string $tenantId, CalendarEventDTO $event): ?string  — return external event id
   updateEvent(string $tenantId, string $eventId, CalendarEventDTO $event): bool
   deleteEvent(string $tenantId, string $eventId): bool
   getEvent(string $tenantId, string $eventId): ?CalendarEventDTO

3. GoogleCalendarAdapter (app/Modules/Calendar/Adapters/):
   Implements CalendarProviderInterface
   Constructor: inject Http (Laravel), config('services.google_calendar')

   createEvent():
     → Cek FeatureKey::GOOGLE_CALENDAR_ENABLED untuk tenant
     → Jika disabled: return null (no-op)
     → POST ke baseUrl/events dengan token tenant (dari tenant_settings.google_oauth_token)
     → Jika error: log + emit NotificationType::CALENDAR_ERROR + return null
     → Sukses: return event id

   updateEvent/deleteEvent/getEvent: pattern serupa

4. NullCalendarAdapter (app/Modules/Calendar/Adapters/):
   Implements interface, semua method return null/false.
   Default ketika config.calendar.provider=null

5. CalendarServiceProvider.php:
   Register CalendarProviderInterface as singleton:
     → Resolve berdasarkan config('services.calendar.provider', 'null')
     → 'google' → GoogleCalendarAdapter
     → null/'null' → NullCalendarAdapter

6. config/services.php:
   'calendar' => ['provider' => env('CALENDAR_PROVIDER', 'null')],
   'google_calendar' => [
     'base_url' => env('GOOGLE_CALENDAR_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
   ],

7. Tests (app/Modules/Calendar/Tests/CalendarAdapterTest.php):
   - NullCalendarAdapter createEvent → null
   - GoogleCalendarAdapter feature disabled → null (tidak ada Http call)
   - GoogleCalendarAdapter feature enabled + Http::fake → return event id
   - createEvent error → return null + NotificationType::CALENDAR_ERROR tersimpan
   - Tenant isolation: tidak ada cross-tenant call
```

---

**QA PROMPT:**
```
1. php artisan test --filter='CalendarAdapterTest' → pass?
2. Tinker:
   >>> $cal = app(CalendarProviderInterface::class)
   >>> get_class($cal) // NullCalendarAdapter (default)?
   >>> $cal->createEvent($tenantId, CalendarEventDTO::from([...])) // null?
3. config calendar provider=google + Http::fake → return event id?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.5.
Files: CalendarEventDTO, CalendarProviderInterface, GoogleCalendarAdapter,
       NullCalendarAdapter, CalendarServiceProvider, CalendarAdapterTest
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: CalendarProviderInterface + Google/Null adapters — calendar foundation"
```

---

### SUB-TASK 5.6 — Booking ↔ Calendar Sync
**Status:** [x] DONE — 2026-05-16
**Depends On:** 5.5 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. app/Modules/Booking/Services/BookingService.php (dari 5.3)
2. app/Modules/Calendar/Adapters/GoogleCalendarAdapter.php (dari 5.5)
3. CLAUDE.md — Booking.calendar_event_id

Flow:
- BookingService::confirm() → sukses createEvent → simpan event_id ke booking
- BookingService::cancel() → deleteEvent
- Booking event_date/event_time_start berubah → updateEvent

Konfirmasi:
- Title event Google Calendar? ("{customer_name} — {event_type} — {booking_code}")
- Jika createEvent gagal: booking tetap CONFIRMED (Calendar adalah side-effect)
- Notifikasi admin jika sync gagal (CALENDAR_ERROR)
```

---

**CODING PROMPT:**
```
Wire BookingService dengan CalendarProviderInterface.

1. Update BookingService.php:
   Inject CalendarProviderInterface

   confirm(Booking $booking):
     → ... existing logic
     → $event = $this->buildCalendarEvent($booking)
     → $eventId = $this->calendar->createEvent($booking->tenant_id, $event)
     → Jika $eventId: $booking->update(['calendar_event_id' => $eventId])

   cancel(Booking $booking, string $reason):
     → Jika $booking->calendar_event_id: $this->calendar->deleteEvent(...)
     → ... existing logic

   rescheduleBooking(Booking $booking, Carbon $newDate, ?string $newTimeStart=null): bool
     → DB::transaction → cek availability tanggal baru (lockForUpdate)
     → Update booking.event_date
     → Update calendar event jika ada calendar_event_id
     → Return success

   private buildCalendarEvent(Booking $booking): CalendarEventDTO
     → Compose title, start_at, end_at dari booking fields
     → Default duration 4 jam jika event_time_end null

2. Tests (app/Modules/Booking/Tests/BookingCalendarSyncTest.php):
   Pakai mock CalendarProviderInterface (NullAdapter atau anonymous class):
   - confirm + adapter return event_id → booking.calendar_event_id terisi
   - confirm + adapter return null (feature disabled) → calendar_event_id tetap null
   - cancel + ada calendar_event_id → deleteEvent dipanggil
   - reschedule → updateEvent dipanggil
   - confirm + adapter throw → booking tetap CONFIRMED, CALENDAR_ERROR notification
```

---

**QA PROMPT:**
```
1. php artisan test --filter='BookingCalendarSyncTest' → pass?
2. Tinker dengan Http::fake (GOOGLE_CALENDAR_BASE_URL):
   >>> $bs = app(BookingService::class)
   >>> $bs->confirm($booking)
   >>> $booking->fresh()->calendar_event_id // tidak null jika fake return id?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.6.
Files: BookingService (updated), BookingCalendarSyncTest
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: Booking ↔ Calendar sync — create/update/delete via provider"
```

---

### SUB-TASK 5.7 — Follow-up Automation (FollowUpJob + Scheduler)
**Status:** [ ] TODO
**Depends On:** 5.6 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — Enum FeatureKey::FOLLOW_UP_AUTOMATION
2. CLAUDE.md — LeadTemperature enum (COLD, WARM, HOT)
3. app/Modules/Lead/Models/Lead.php
4. app/Modules/Conversation/Models/Conversation.php (last_message_at)
5. routes/console.php / app/Console/Kernel.php — scheduler

Follow-up triggers:
1. Lead WARM/HOT dengan last_message_at > 24 jam dan belum di-follow-up hari ini
2. Booking AWAITING_DP > 48 jam tanpa invoice paid → reminder
3. Invoice OVERDUE → reminder
4. Booking CONFIRMED H-7 dari event_date → reminder + checklist (opsional)

Konfirmasi:
- Apakah follow-up bisa dimatikan per tenant? (ya — feature flag FOLLOW_UP_AUTOMATION)
- Frekuensi schedule? (hourly check, tapi guard tidak spam: max 1 follow-up per
  conversation per 24 jam, track via Redis cache key)
- Channel? (WA only di Phase 5)
```

---

**CODING PROMPT:**
```
Buat Follow-up automation.

1. Enum FollowUpReason (app/Modules/Shared/Enums/):
   STALE_LEAD          — lead tidak respon > 24h
   BOOKING_PENDING_DP  — booking AWAITING_DP > 48h
   INVOICE_OVERDUE
   EVENT_REMINDER_H7

2. Migration create_follow_up_logs_table (2026_05_12_900001):
   id uuid PK
   tenant_id uuid FK
   conversation_id uuid nullable FK
   booking_id uuid nullable FK
   invoice_id uuid nullable FK
   reason varchar(50)                — FollowUpReason
   sent_at timestamp
   message_body text
   delivered bool default false
   metadata jsonb default '{}'
   created_at, updated_at
   INDEX: [tenant_id, sent_at]
   INDEX: [conversation_id, reason]   — guard: 1 per conversation per reason per day

3. FollowUpService.php (app/Modules/FollowUp/Services/):
   Constructor: inject TenantFeatureService, ChannelGatewayInterface,
                Repositories (Conversation, Booking, Invoice, Lead), Redis

   findCandidates(string $tenantId): array<FollowUpCandidateDTO>
     → 4 query terpisah untuk 4 reason, gabungkan
     → Filter: skip jika sudah ada FollowUpLog same conversation+reason dalam 24h

   sendFollowUp(FollowUpCandidateDTO $candidate): bool
     → Cek Redis lock key follow_up:{conv_id}:{reason}:{date} → skip jika ada
     → Generate message body sesuai reason (template, bukan LLM)
     → gateway->sendText via WaAccount aktif
     → Simpan FollowUpLog
     → Set Redis lock TTL 24h

4. FollowUpJob.php (app/Modules/FollowUp/Jobs/):
   Queue: 'follow_ups'
   implements ShouldQueue
   Param: string $tenantId
   handle():
     → Skip jika FOLLOW_UP_AUTOMATION feature disabled
     → $candidates = service->findCandidates($tenantId)
     → foreach: service->sendFollowUp($c)

5. ScheduleFollowUpsCommand.php (app/Modules/FollowUp/Console/):
   Signature: 'followups:schedule'
   handle(): foreach tenant aktif → dispatch FollowUpJob($tenant->id)

6. Register di app/Console/Kernel.php (atau routes/console.php Laravel 11):
   $schedule->command('followups:schedule')->hourly();

7. FollowUpCandidateDTO (app/Modules/Shared/DTOs/):
   reason: string
   tenant_id: string
   conversation_id: string|null
   booking_id: string|null
   invoice_id: string|null
   to_phone: string
   wa_account_id: string
   context_data: array

8. Tests (app/Modules/FollowUp/Tests/FollowUpServiceTest.php):
   - findCandidates STALE_LEAD: lead WARM last_message > 24h → muncul
   - findCandidates STALE_LEAD: lead WARM last_message < 24h → tidak muncul
   - findCandidates BOOKING_PENDING_DP > 48h → muncul
   - findCandidates INVOICE_OVERDUE → muncul
   - sendFollowUp → FollowUpLog tersimpan, gateway dipanggil (Http::fake)
   - sendFollowUp duplikat (cache lock ada) → tidak kirim, return false
   - FOLLOW_UP_AUTOMATION disabled → FollowUpJob no-op
   - Tenant isolation
```

---

**QA PROMPT:**
```
1. php artisan test --filter='FollowUpServiceTest' → pass?
2. php artisan followups:schedule → tidak error?
3. php artisan schedule:list | grep followups → tampil hourly?
4. Manual:
   >>> $svc = app(FollowUpService::class)
   >>> $cands = $svc->findCandidates($tenantId)
   >>> count($cands) // tergantung seed
   >>> $svc->sendFollowUp($cands[0]) // true?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.7.
Files: FollowUpReason enum, FollowUpCandidateDTO, migration, FollowUpService,
       FollowUpJob, ScheduleFollowUpsCommand, FollowUpServiceTest
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: FollowUpService — stale lead, pending DP, overdue, H-7 reminder"
```

---

### SUB-TASK 5.8 — Filament Tenant Panel: Bookings + Invoices + Calendar Status
**Status:** [ ] TODO
**Depends On:** 5.7 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 10 (Filament UI, panggil Service yang sama)
2. app/Providers/Filament/TenantPanelProvider.php
3. app/Filament/Tenant/Pages/InboxPage.php — pola custom page
4. app/Modules/Booking/Services/BookingService.php
5. app/Modules/Invoice/Services/InvoiceService.php

UI yang dibutuhkan:
- BookingResource: list + detail booking; action Confirm, Cancel, Reschedule, Send Invoice
- InvoiceResource: list + detail; action Send, Resend, Mark Paid
- Calendar Status: settings page (per tenant) — enable/disable Google Calendar,
  paste OAuth token (Phase 5 manual, Phase 6 OAuth flow)
- Dashboard widget: upcoming bookings (next 14 days), overdue invoices count
```

---

**CODING PROMPT:**
```
Buat Filament Tenant resources untuk Phase 5.

1. BookingResource (app/Filament/Tenant/Resources/):
   Table columns: booking_code, customer_name (masked phone), event_date,
                  event_type, status badge, total_amount, action menu
   Filters: status (multi), event_date range, package
   Actions:
     ConfirmAction: BookingService::confirm — confirm dialog
     CancelAction: BookingService::cancel — confirm + reason field
     RescheduleAction: form (new date) → BookingService::rescheduleBooking
     SendInvoiceAction: form (type DP/PELUNASAN, amount, due_date)
                        → InvoiceService::issue → InvoiceService::send
   Form (create/edit): event_date, event_time_start/end, event_type, location,
                       guest_count, package_id, customer_name, customer_phone,
                       total_amount, dp_amount, notes

2. InvoiceResource (app/Filament/Tenant/Resources/):
   Table columns: invoice_number, booking link, type, amount, due_date,
                  status badge, sent_count
   Filters: status, due_date range
   Actions:
     SendAction: InvoiceService::send (cek canResend, disable jika tidak)
     ResendAction: same as Send
     MarkPaidAction: form upload payment_proof_url (atau text URL)
                     → InvoiceService::markPaid

3. CalendarSettingsPage (app/Filament/Tenant/Pages/):
   Form per tenant:
     - GOOGLE_CALENDAR_ENABLED toggle (FeatureKey)
     - google_oauth_token text (Phase 5 manual paste, Phase 6 OAuth)
   Save → update tenant_settings

4. Dashboard Widgets (app/Filament/Tenant/Widgets/):
   UpcomingBookingsWidget: table next 14 hari
   OverdueInvoicesWidget: stat count
   PendingHandoffsWidget: stat count (sudah ada di 4.7?) — pastikan tetap

5. Update TenantPanelProvider:
   Navigation:
     "WhatsApp" group → WaAccountResource
     "Inbox"
     "Bookings" group → BookingResource, InvoiceResource
     "Settings" group → TenantSettings, PolicySettings, CalendarSettingsPage
   Tambahkan widgets dashboard

6. Tests (tests/Feature/Filament/FilamentTenantBookingTest.php):
   - GET /app/bookings → 200 (tenant admin)
   - Confirm booking → status=CONFIRMED
   - Cancel booking → status=CANCELLED
   - Reschedule booking → event_date berubah, availability re-checked
   - SendInvoice action → Invoice issued + sent
   - Tenant isolation
   
7. Tests (tests/Feature/Filament/FilamentTenantInvoiceTest.php):
   - Send invoice → status=SENT, sent_count=1
   - Resend over max → action disabled / blocked
   - MarkPaid → status=PAID, booking.status update
```

---

**QA PROMPT:**
```
1. php artisan test --filter='FilamentTenantBookingTest|FilamentTenantInvoiceTest' → pass?
2. Manual /app/bookings → 200, list booking muncul?
3. Confirm action → DB booking.status=CONFIRMED?
4. SendInvoice action → Invoice tersimpan + DB SentLog?
5. Calendar settings: toggle GOOGLE_CALENDAR_ENABLED → tenant_settings update?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.8.
KANBAN-PHASE-5.md: [x] DONE
Git commit: "feat: Filament Tenant — BookingResource, InvoiceResource, Calendar settings"
```

---

### SUB-TASK 5.9 — E2E Integration Test Phase 5
**Status:** [ ] TODO
**Depends On:** 5.8 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. tests/Feature/Integration/WaFlowIntegrationTest.php (pola Phase 4)
2. PRINSIP 9 — pakai MockLlmAdapter
3. PRINSIP 14 — concurrent booking lock

Skenario E2E Phase 5: simulate full booking + invoice + calendar + follow-up flow
tanpa real WA / real Google Calendar.
```

---

**CODING PROMPT:**
```
File: tests/Feature/Integration/BookingFlowIntegrationTest.php

setUp():
- Seed tenant + WaAccount CONNECTED + Package + Asset PRICELIST
- MockLlmAdapter + Http::fake untuk wa-gateway + google-calendar
- Carbon::setTestNow business-hours (Fri 10:00 WIB)

Tests:
1. test_full_pricelist_flow_pdf_mode():
   → Policy PRICELIST_MODE=pdf
   → POST /webhook/inbound message "kak boleh minta pricelist?"
   → mock intent=ask_package_list
   → pipeline run
   → assert: Http::assertSent dispatch dengan message_type=document
   → assert: ConversationMessage outbound tersimpan

2. test_pricelist_blocked_by_policy_disabled():
   → Policy PRICELIST_MODE=disabled
   → Inbound ask_price
   → assert: decision.blocked_actions contain 'send_pricelist'
   → assert: composer reply mengandung 'manual_quote' atau alternatives

3. test_booking_create_draft_via_pipeline():
   → entity_cache.event_date=2026-09-01, intent=request_booking
   → assert: Booking tersimpan status=DRAFT
   → assert: conversation.stage=WAITING_BOOKING

4. test_booking_concurrent_date_conflict():
   → Existing CONFIRMED booking 2026-09-01 resepsi
   → New createDraft same date+type → return null
   → composer alternatives ditawarkan

5. test_invoice_flow_issue_send_pay():
   → Booking CONFIRMED
   → InvoiceService::issue DP 5jt
   → InvoiceService::send → Http::assertSent dispatch text
   → InvoiceService::markPaid → booking.status=PAID, conv.stage=BOOKING

6. test_booking_confirm_creates_calendar_event():
   → Config calendar.provider=google
   → Http::fake google → return event id 'evt-abc'
   → BookingService::confirm → booking.calendar_event_id='evt-abc'

7. test_booking_cancel_deletes_calendar_event():
   → Booking confirmed dengan calendar_event_id
   → BookingService::cancel → Http::assertSent DELETE google calendar

8. test_follow_up_stale_lead_sent():
   → Conversation WARM, last_message_at 26h ago
   → Run FollowUpJob → FollowUpLog tersimpan
   → Http::assertSent dispatch
   → Run lagi → tidak ada FollowUpLog baru (idempotency)

9. test_follow_up_feature_disabled_no_op():
   → FOLLOW_UP_AUTOMATION feature disabled
   → FollowUpJob no-op
```

---

**QA PROMPT:**
```
1. php artisan test --filter='BookingFlowIntegrationTest' → pass semua?
2. Pricelist mode=disabled flow tetap berfungsi (no crash)?
3. Calendar event id sinkron ke Booking?
4. Follow-up idempotent: tidak duplicate dalam 24h?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 5.9.
KANBAN-PHASE-5.md: [x] DONE
Git commit: "test: BookingFlowIntegrationTest — E2E pricelist/booking/invoice/calendar/follow-up"
```

---

### INTEGRATION CHECKPOINT — PHASE 5
**Status:** [ ] TODO

**PROMPT:**
```
Integration Checkpoint akhir Phase 5.

Jalankan semua verifikasi:

1. make fresh → tidak ada error (termasuk bookings, invoices, follow_up_logs tables)
2. make test → 100% pass (target: Phase 4 (413) + 60+ test baru Phase 5 = 470+)
3. docker compose ps → semua 10 container running

4. Pricelist Flow:
   [ ] PRICELIST_MODE=pdf → kirim file Asset PRICELIST
   [ ] PRICELIST_MODE=text → composer pakai buildTextPricelist()
   [ ] PRICELIST_MODE=hybrid → text dulu + file
   [ ] PRICELIST_MODE=disabled → blocked_action send_pricelist
   [ ] PRICELIST_MIN_REQUIREMENT=after_qualification → cek stage

5. Booking System:
   [ ] createDraft → Booking DRAFT, conv.stage=WAITING_BOOKING
   [ ] Concurrent lock: 2 createDraft same date+type → 1 sukses, 1 null
   [ ] confirm → status=CONFIRMED, conv.stage=BOOKING, calendar event dibuat
   [ ] cancel → status=CANCELLED, calendar event dihapus
   [ ] reschedule → availability re-check + calendar update
   [ ] booking_code unique format BKG-YYYYMM-XXXX

6. Invoice System:
   [ ] issue → invoice ISSUED, booking AWAITING_DP, conv.stage=INVOICE_PHASE
   [ ] send → status=SENT, sent_count++, gateway dipanggil
   [ ] canResend false saat sent_count >= INVOICE_MAX_RESEND
   [ ] markPaid → status=PAID, booking.status update
   [ ] overdue scan: due_date lewat + SENT → status=OVERDUE

7. Google Calendar:
   [ ] FeatureKey GOOGLE_CALENDAR_ENABLED off → no-op
   [ ] on + Http::fake → event id tersimpan ke booking
   [ ] createEvent error → NotificationType::CALENDAR_ERROR
   [ ] NullCalendarAdapter default jika provider=null

8. Follow-up:
   [ ] STALE_LEAD: lead WARM > 24h tanpa respon → kirim
   [ ] BOOKING_PENDING_DP > 48h → kirim
   [ ] INVOICE_OVERDUE → kirim
   [ ] EVENT_REMINDER_H7 → kirim
   [ ] Idempotency Redis lock 24h per conv+reason
   [ ] FOLLOW_UP_AUTOMATION disabled → FollowUpJob no-op

9. Filament Tenant Panel:
   [ ] /app/bookings → 200, list + filter
   [ ] /app/invoices → 200
   [ ] BookingResource Confirm/Cancel/Reschedule/SendInvoice actions
   [ ] InvoiceResource Send/Resend/MarkPaid actions
   [ ] CalendarSettingsPage toggle feature
   [ ] Dashboard widgets: upcoming bookings, overdue invoices

10. Semua PRINSIP dari Phase 1-4 masih terjaga:
    [ ] PRINSIP 1 — LLM tidak putuskan bisnis (booking/invoice rules di PHP)
    [ ] PRINSIP 14 — Concurrent Booking Lock dengan lockForUpdate verified
    [ ] PRINSIP 9 — MockLlmAdapter di semua unit test
    [ ] PRINSIP 3 — Tenant isolation di booking, invoice, follow_up_logs
    [ ] PRINSIP 4 — CalendarProviderInterface dengan adapter swap-able
    [ ] Phone number masked di logs dan email

Exit Gate Checklist:
[ ] php artisan test → 100% PASS
[ ] Pricelist 4 mode bekerja
[ ] Booking concurrent lock test PASS
[ ] Invoice send + resend + mark paid
[ ] Google Calendar sync via Http::fake (real OAuth → Phase 6)
[ ] Follow-up 4 reasons + idempotency
[ ] Filament: bookings, invoices, calendar settings
[ ] E2E BookingFlowIntegrationTest pass

Jika semua PASS:
1. Update PROGRESS.md: Checkpoint Phase 5 DONE, Gate OPEN
2. Tulis CATATAN PENTING ANTAR SUB-TASK Phase 5:
   - Pricelist mode dan policy
   - Booking + calendar sync points
   - Invoice resend guard
   - Follow-up idempotency strategy
   - Hal penting untuk Phase 6 (Analytics, real OAuth, PDF, multi-channel)
3. Buat git tag:
   git tag -a v0.6-commerce-complete -m "Booking/Invoice/Calendar/Follow-up complete. Tests: X pass."
4. Lanjut ke KANBAN-PHASE-6.md
```
