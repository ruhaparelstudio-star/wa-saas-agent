# KANBAN-PHASE-6.md — Analytics, Google OAuth, PDF Invoice, Storage, Multi-channel
# PRE-REQUISITE: Phase 5 gate harus OPEN di PROGRESS.md
# Target: Analytics dashboard real, Google OAuth 2.0 nyata, PDF invoice tersimpan di R2,
#         Multi-channel foundation dengan Email adapter, export CSV.
# Exit Gate: php artisan test 100% pass; analytics KPI akurat;
#            Google OAuth flow → token tersimpan di tenant_settings;
#            Invoice dikirim sebagai PDF file via WA;
#            Email channel kirim invoice/follow-up;
#            Export CSV booking/invoice/lead bisa diunduh.

---

## CEK SEBELUM MULAI

```
Buka PROGRESS.md, pastikan:
[ ] Integration Checkpoint Phase 5 → Gate: OPEN
[ ] Git tag v0.6-commerce-complete sudah ada
[ ] 529 tests passing dari Phase 5

Jika belum: selesaikan Phase 5 dulu.

Yang sudah ada dari Phase 0-5 (JANGAN dibuat ulang):
[x] Booking, Invoice, FollowUp, Conversation, Lead models (Phase 5)
[x] BookingService, InvoiceService, FollowUpService (Phase 5)
[x] CalendarProviderInterface + GoogleCalendarAdapter (Phase 5)
[x] StorageProviderInterface — contract sudah defined (Phase 1), BELUM ada adapter
[x] ChannelGatewayInterface → WhatsAppGatewayAdapter (Phase 4)
[x] FeatureKey: ANALYTICS_ADVANCED, MULTI_CHANNEL (Phase 1)
[x] TenantFeatureService::isEnabled() (Phase 1)
[x] NotificationService (Phase 4)
[x] WaAccountRepository::getActiveForTenant() (Phase 4)
[x] Filament Tenant: BookingResource, InvoiceResource, CalendarSettingsPage (Phase 5)
[x] Filament Tenant: Widgets UpcomingBookings, OverdueInvoices (Phase 5)
[x] config/services.php — resend key, wa_gateway, google_calendar slots
[x] phpunit.xml memory_limit=512M (Phase 5 fix)
```

---

### SUB-TASK 6.1 — AnalyticsService (Lead Funnel, Revenue, Conversion)
**Status:** [ ] TODO
**Depends On:** Phase 5 gate OPEN
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 1, PRINSIP 3 (Tenant Isolation), PRINSIP 5 (UUID)
2. CLAUDE.md — Enum FeatureKey::ANALYTICS_ADVANCED
3. app/Modules/Conversation/Models/Conversation.php — stage, created_at, lead_id
4. app/Modules/Lead/Models/Lead.php — temperature, created_at
5. app/Modules/Booking/Models/Booking.php — status, total_amount, event_date
6. app/Modules/Invoice/Models/Invoice.php — status, amount, paid_at, type
7. app/Modules/TenantConfig/Services/TenantFeatureService.php — isEnabled()
8. app/Modules/Plans/Services/FeatureGateService.php — pola feature gating

Konfirmasi:
- Apakah analytics butuh table sendiri? (Tidak di Phase 6 — hitung langsung dari existing tables
  dengan query agregasi; Phase 7+ bisa add materialized views/event-sourcing)
- Apa saja KPI minimum? (Lead funnel, conversion rate, revenue per period, response time avg)
- Jika ANALYTICS_ADVANCED disabled: return basic metrics saja (lead count, booking count)
```

---

**CODING PROMPT:**
```
Buat AnalyticsService + DTOs.

1. AnalyticsPeriodDTO (app/Modules/Shared/DTOs/):
   start_date: Carbon
   end_date: Carbon
   label: string        — 'last_7_days'|'last_30_days'|'this_month'|'custom'

2. LeadFunnelDTO (app/Modules/Shared/DTOs/):
   stage: string
   count: int
   percentage: float

3. RevenueMetricDTO (app/Modules/Shared/DTOs/):
   period_label: string
   total_revenue: int      — IDR, sum invoice.amount WHERE status=PAID
   dp_revenue: int         — type=DP
   pelunasan_revenue: int  — type=PELUNASAN
   invoice_count: int
   paid_count: int

4. ConversionMetricDTO (app/Modules/Shared/DTOs/):
   total_leads: int
   leads_to_booking: int
   conversion_rate: float  — percentage
   avg_days_to_booking: float

5. ResponseTimeMetricDTO (app/Modules/Shared/DTOs/):
   avg_first_response_minutes: float
   avg_handling_minutes: float
   sample_count: int

6. TenantAnalyticsSummaryDTO (app/Modules/Shared/DTOs/):
   period: AnalyticsPeriodDTO
   lead_funnel: array<LeadFunnelDTO>
   revenue: RevenueMetricDTO
   conversion: ConversionMetricDTO
   response_time: ResponseTimeMetricDTO
   top_packages: array              — [['package_name'=>, 'booking_count'=>]]
   is_advanced: bool                — apakah ANALYTICS_ADVANCED enabled

7. AnalyticsService (app/Modules/Analytics/Services/):
   Constructor: inject TenantFeatureService, DB

   getSummary(string $tenantId, AnalyticsPeriodDTO $period): TenantAnalyticsSummaryDTO
     → Compose semua metric dalam satu DTO
     → Jika !isEnabled(ANALYTICS_ADVANCED): hanya return basic (lead count, booking count)
     → Jika enabled: return full summary

   getLeadFunnel(string $tenantId, AnalyticsPeriodDTO $period): array<LeadFunnelDTO>
     → GROUP BY conversation stage, count per stage
     → Hitung percentage dari total
     → Only conversations created dalam period

   getRevenue(string $tenantId, AnalyticsPeriodDTO $period): RevenueMetricDTO
     → SUM invoices WHERE status=PAID AND paid_at BETWEEN period
     → Breakdown by InvoiceType DP vs PELUNASAN

   getConversion(string $tenantId, AnalyticsPeriodDTO $period): ConversionMetricDTO
     → total_leads: conversations.created_at dalam period
     → leads_to_booking: distinct conversation_id yang ada booking status != DRAFT|EXPIRED|CANCELLED
     → avg_days_to_booking: AVG(booking.created_at - conversation.created_at) in days

   getResponseTime(string $tenantId, AnalyticsPeriodDTO $period): ResponseTimeMetricDTO
     → avg time dari conversation.created_at ke first outbound ConversationMessage
     → Pakai DB raw query untuk efisiensi
     → Kembalikan null fields jika sample_count < 5

   getTopPackages(string $tenantId, AnalyticsPeriodDTO $period): array
     → GROUP BY booking.package_id, COUNT, JOIN packages.name
     → Limit 5, sort DESC
     → Hanya booking status NOT IN [DRAFT, EXPIRED, CANCELLED]

   makePeriod(string $label = 'last_30_days'): AnalyticsPeriodDTO
     → Helper: 'last_7_days', 'last_30_days', 'this_month', 'last_month'

8. AnalyticsServiceProvider (app/Modules/Analytics/Providers/):
   Register AnalyticsService as singleton.
   Register di config/app.php providers.

9. Tests (app/Modules/Analytics/Tests/AnalyticsServiceTest.php):
   - getLeadFunnel: seed conversations di 3 stage → assert count per stage benar
   - getRevenue: seed 3 invoices PAID + 2 SENT → total hanya PAID
   - getConversion: 10 conversations, 4 ada booking non-draft → rate=40%
   - getTopPackages: 3 bookings paket A, 1 paket B → paket A first
   - getSummary dengan ANALYTICS_ADVANCED=false → is_advanced=false, funnel kosong
   - getSummary dengan ANALYTICS_ADVANCED=true → full data
   - Tenant isolation: metric tenant A tidak tercampur tenant B
   - makePeriod 'last_30_days' → start_date = today-30, end_date = today
```

---

**QA PROMPT:**
```
1. php artisan test --filter='AnalyticsServiceTest' → semua pass?
2. Tinker:
   >>> $svc = app(AnalyticsService::class)
   >>> $period = $svc->makePeriod('last_30_days')
   >>> $summary = $svc->getSummary($tenantId, $period)
   >>> $summary->revenue->total_revenue  // 0 jika belum ada data?
3. Feature flag: isEnabled(ANALYTICS_ADVANCED)=false → getSummary->is_advanced=false?
4. Tenant isolation: summary tenant A tidak include data tenant B?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.1.
Files: AnalyticsPeriodDTO, LeadFunnelDTO, RevenueMetricDTO, ConversionMetricDTO,
       ResponseTimeMetricDTO, TenantAnalyticsSummaryDTO, AnalyticsService,
       AnalyticsServiceProvider, AnalyticsServiceTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: AnalyticsService — lead funnel, revenue, conversion metrics"
```

---

### SUB-TASK 6.2 — Filament Analytics Dashboard (Tenant + Superadmin)
**Status:** [ ] TODO
**Depends On:** 6.1 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. app/Modules/Analytics/Services/AnalyticsService.php (dari 6.1)
2. app/Providers/Filament/TenantPanelProvider.php
3. app/Providers/Filament/SuperadminPanelProvider.php
4. app/Filament/Tenant/Widgets/ — pola UpcomingBookingsWidget
5. CLAUDE.md — PRINSIP 10 (Filament pakai Service yang sama)

UI yang dibutuhkan:
- Tenant: AnalyticsDashboardPage (/app/analytics) dengan period filter + 5 chart/stat
- Superadmin: CrossTenantAnalyticsPage (/superadmin/analytics) — overview semua tenant
- Widget tambahan di tenant dashboard: RevenueWidget, ConversionWidget
```

---

**CODING PROMPT:**
```
Buat Filament Analytics pages dan widgets.

1. AnalyticsDashboardPage (app/Filament/Tenant/Pages/):
   URL: /app/analytics
   Inject AnalyticsService
   Period filter: Select (last_7_days, last_30_days, this_month, last_month)
   → Default: last_30_days
   → Gating: jika ANALYTICS_ADVANCED disabled, tampil notice upgrade + basic stats
   
   Sections:
   a. Stats Overview (Filament Stats widgets):
      - Total Leads (period)
      - Total Bookings (confirmed+)
      - Total Revenue (IDR formatted)
      - Conversion Rate (%)
   
   b. Lead Funnel Table (TableWidget atau custom view):
      Kolom: Stage | Count | Percentage (progress bar)
   
   c. Top Packages (TableWidget atau simple list):
      Kolom: Package Name | Booking Count
   
   d. Response Time Stats (jika advanced):
      - Avg First Response (menit)
      - Avg Handling Time (menit)

2. RevenueStatWidget (app/Filament/Tenant/Widgets/):
   Extends Filament StatsOverviewWidget
   Stat: Total Revenue bulan ini (dari AnalyticsService::getRevenue)
   Tambahkan ke TenantPanelProvider dashboard widgets list

3. ConversionStatWidget (app/Filament/Tenant/Widgets/):
   Stat: Conversion Rate % (leads → booking), bulan ini

4. CrossTenantAnalyticsPage (app/Filament/Superadmin/Pages/):
   URL: /superadmin/analytics
   Table: Tenant | Active Leads | Bookings | Revenue (IDR) | Conversion %
   → Iterate semua tenant aktif, call getSummary per tenant
   → Sort by revenue DESC default
   Period filter same as tenant page

5. Update TenantPanelProvider:
   Navigation: tambahkan "Analytics" di Filament navigation group "Insights"
   Widgets dashboard: tambahkan RevenueStatWidget, ConversionStatWidget

6. Update SuperadminPanelProvider:
   Navigation: tambahkan CrossTenantAnalyticsPage di group "Reports"

7. Tests (tests/Feature/Filament/FilamentAnalyticsTest.php):
   - GET /app/analytics → 200 (tenant admin dengan feature enabled)
   - GET /app/analytics dengan feature disabled → 200 + upgrade notice (tidak error)
   - Stats ditampilkan dalam IDR format (Rp 1.000.000)
   - GET /superadmin/analytics → 200 (superadmin)
   - Tenant admin tidak bisa akses /superadmin/analytics → 403/redirect
```

---

**QA PROMPT:**
```
1. php artisan test --filter='FilamentAnalyticsTest' → pass?
2. Manual:
   - /app/analytics → 200, stats ditampilkan?
   - Period filter change → stats berubah?
   - Feature ANALYTICS_ADVANCED=false → notice muncul bukan error?
   - /superadmin/analytics → 200 dari superadmin account?
3. Tenant isolation: superadmin analytics tidak mix data antar tenant?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.2.
Files: AnalyticsDashboardPage, RevenueStatWidget, ConversionStatWidget,
       CrossTenantAnalyticsPage, FilamentAnalyticsTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: Filament Analytics — tenant dashboard + superadmin cross-tenant"
```

---

### SUB-TASK 6.3 — Google OAuth 2.0 Real Flow
**Status:** [ ] TODO
**Depends On:** 6.2 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 4 (interface CalendarProviderInterface)
2. app/Modules/Calendar/Adapters/GoogleCalendarAdapter.php (dari 5.5)
3. app/Filament/Tenant/Pages/CalendarSettingsPage.php (dari 5.8)
4. config/services.php — google_calendar section
5. database/migrations/*_add_google_oauth_token_to_tenant_settings.php

Phase 5 approach: manual paste token di CalendarSettingsPage.
Phase 6: real OAuth2 flow — tenant klik "Connect Google Calendar" → redirect ke Google
         → callback → store access_token + refresh_token → auto-refresh sebelum expired.

Google OAuth2 endpoints:
- Authorization: https://accounts.google.com/o/oauth2/v2/auth
- Token exchange: https://oauth2.googleapis.com/token
- Scopes: https://www.googleapis.com/auth/calendar.events

Konfirmasi:
- Token di-store di mana? (tenant_settings.google_oauth_token → JSON {access_token, refresh_token, expires_at})
- Jika token expired: auto-refresh sebelum API call
- Jika refresh gagal: emit NotificationType::CALENDAR_ERROR, clear token
- GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET di .env (tidak hardcode)
```

---

**CODING PROMPT:**
```
Implementasi Google OAuth 2.0 flow tanpa paket tambahan (hanya Laravel HTTP client).

1. config/services.php → tambahkan:
   'google_oauth' => [
     'client_id'     => env('GOOGLE_CLIENT_ID', ''),
     'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
     'redirect_uri'  => env('GOOGLE_REDIRECT_URI', ''),
     'scopes'        => ['https://www.googleapis.com/auth/calendar.events'],
   ],

2. .env.example → tambahkan:
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_REDIRECT_URI=http://localhost:8080/app/calendar/oauth/callback

3. GoogleOAuthService (app/Modules/Calendar/Services/):
   getAuthorizationUrl(string $tenantId): string
     → Build Google OAuth URL dengan state=base64(tenantId) + PKCE optional
     → URL: https://accounts.google.com/o/oauth2/v2/auth?...

   exchangeCode(string $code, string $state): array
     → POST https://oauth2.googleapis.com/token
     → Return {access_token, refresh_token, expires_in, token_type}
     → Store ke tenant_settings: google_oauth_token = JSON

   refreshAccessToken(string $tenantId): ?string
     → Baca refresh_token dari tenant_settings
     → POST /token dengan grant_type=refresh_token
     → Update tenant_settings.google_oauth_token.access_token + expires_at
     → Return new access_token atau null jika gagal

   getValidToken(string $tenantId): ?string
     → Cek expires_at — jika < 5 menit lagi expire: refreshAccessToken
     → Return current access_token atau null

   revokeToken(string $tenantId): void
     → POST https://oauth2.googleapis.com/revoke
     → Clear tenant_settings.google_oauth_token

4. Update GoogleCalendarAdapter:
   Saat ini: ambil token dari tenant_settings.google_oauth_token (raw string)
   Ubah ke: inject GoogleOAuthService, panggil getValidToken($tenantId)
   Jika getValidToken return null: return null/false (no-op), emit CALENDAR_ERROR

5. GoogleOAuthController (app/Http/Controllers/):
   GET /app/calendar/oauth/redirect → GoogleOAuthService::getAuthorizationUrl → redirect
   GET /app/calendar/oauth/callback → exchange code → simpan token → redirect ke CalendarSettingsPage

   Middleware: auth (tenant admin), verify state parameter

6. Route: routes/web.php atau Filament custom route
   Route::middleware(['auth', 'verified'])->group(function () {
       Route::get('/app/calendar/oauth/redirect', [GoogleOAuthController::class, 'redirect'])
            ->name('calendar.oauth.redirect');
       Route::get('/app/calendar/oauth/callback', [GoogleOAuthController::class, 'callback'])
            ->name('calendar.oauth.callback');
   });

7. Update CalendarSettingsPage:
   Ganti field manual token paste dengan:
   - Status: "Connected" / "Not Connected" berdasarkan token exists + expires
   - Tombol "Connect Google Calendar" → redirect ke OAuth flow
   - Tombol "Disconnect" → revokeToken
   - Informasi: connected_at, expires_at

8. Tests (app/Modules/Calendar/Tests/GoogleOAuthServiceTest.php):
   Pakai Http::fake untuk semua Google API calls:
   - getAuthorizationUrl → mengandung client_id, scope, redirect_uri
   - exchangeCode → Http::fake token response → tenant_settings tersimpan
   - refreshAccessToken → Http::fake → access_token di-update
   - getValidToken: token masih valid → tidak call refresh
   - getValidToken: token expired (<5 menit) → call refresh
   - refreshAccessToken gagal → return null + CALENDAR_ERROR
   - revokeToken → Http::assertSent ke revoke endpoint, token cleared

9. Tests (tests/Feature/GoogleOAuthFlowTest.php):
   - GET /app/calendar/oauth/redirect → redirect ke Google (302, location contains accounts.google.com)
   - GET /app/calendar/oauth/callback dengan valid code+state → token disimpan, redirect ke settings
   - GET /app/calendar/oauth/callback dengan invalid state → 400/redirect dengan error
```

---

**QA PROMPT:**
```
1. php artisan test --filter='GoogleOAuthServiceTest|GoogleOAuthFlowTest' → pass?
2. Http::fake verify: exchangeCode POST ke https://oauth2.googleapis.com/token?
3. CalendarSettingsPage: tombol Connect muncul?
4. Jika token expired: getValidToken auto-refresh (Http::fake)?
5. revokeToken → tenant_settings.google_oauth_token cleared?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.3.
Files: GoogleOAuthService, GoogleOAuthController, routes/web.php (updated),
       CalendarSettingsPage (updated), GoogleCalendarAdapter (updated),
       GoogleOAuthServiceTest, GoogleOAuthFlowTest, .env.example (updated)
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: Google OAuth 2.0 real flow — auto token refresh, calendar connect/disconnect"
```

---

### SUB-TASK 6.4 — R2StorageAdapter (StorageProviderInterface)
**Status:** [ ] TODO
**Depends On:** 6.3 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 4 (interface StorageProviderInterface)
2. app/Modules/Shared/Contracts/StorageProviderInterface.php
   Methods: upload(file, path): string | getUrl(path): string | delete(path): bool
3. config/filesystems.php — laravel disk config
4. CLAUDE.md — "Object Storage: Cloudflare R2 / S3-compatible"

Pendekatan:
- R2 adalah S3-compatible → pakai Laravel's built-in S3 driver (Flysystem)
- Perlu package: league/flysystem-aws-s3-v3 (cek jika sudah ada, jika tidak: composer require)
- R2StorageAdapter wraps Laravel Storage facade dengan disk 'r2'
- NullStorageAdapter: untuk testing (return dummy path/url)
- Default di Phase 6: NullStorageAdapter jika R2 tidak dikonfigurasi

Konfirmasi:
- Config keys yang dibutuhkan: R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY,
  R2_BUCKET, R2_ENDPOINT (format: https://<accountid>.r2.cloudflarestorage.com)
- Public URL: R2_PUBLIC_URL (custom domain atau r2.dev public bucket URL)
- Visibility: semua file untuk invoice = 'public' (bisa diakses customer)
```

---

**CODING PROMPT:**
```
Buat R2StorageAdapter + NullStorageAdapter.

1. NullStorageAdapter (app/Modules/Shared/Storage/):
   Implements StorageProviderInterface
   upload(): return 'null/' . basename($path)
   getUrl(): return 'https://storage.null/null/' . $path
   delete(): return true

2. R2StorageAdapter (app/Modules/Storage/Adapters/):
   Implements StorageProviderInterface
   Constructor: inject Storage (Laravel), config('filesystems.disks.r2')

   upload(mixed $file, string $path): string
     → Storage::disk('r2')->put($path, $file, 'public')
     → Return $path

   getUrl(string $path): string
     → Jika config R2_PUBLIC_URL tersedia: return R2_PUBLIC_URL . '/' . $path
     → Else: Storage::disk('r2')->url($path)

   delete(string $path): bool
     → Storage::disk('r2')->delete($path)

3. config/filesystems.php → tambahkan disk 'r2':
   'r2' => [
     'driver' => 's3',
     'key'    => env('R2_ACCESS_KEY_ID', ''),
     'secret' => env('R2_SECRET_ACCESS_KEY', ''),
     'region' => 'auto',
     'bucket' => env('R2_BUCKET', ''),
     'endpoint'=> env('R2_ENDPOINT', ''),
     'url'     => env('R2_PUBLIC_URL', ''),
     'visibility' => 'public',
     'use_path_style_endpoint' => true,
   ],

4. .env.example → tambahkan:
   R2_ACCESS_KEY_ID=
   R2_SECRET_ACCESS_KEY=
   R2_BUCKET=
   R2_ENDPOINT=
   R2_PUBLIC_URL=

5. StorageServiceProvider (app/Modules/Storage/Providers/):
   Register StorageProviderInterface:
     → Jika R2_ACCESS_KEY_ID tersedia di env: R2StorageAdapter
     → Else: NullStorageAdapter
   Register di config/app.php providers.

6. Cek package: jalankan docker compose exec app composer show | grep flysystem-aws
   Jika tidak ada: composer require league/flysystem-aws-s3-v3

7. Tests (app/Modules/Storage/Tests/StorageAdapterTest.php):
   - NullStorageAdapter upload → return path string, tidak throw
   - NullStorageAdapter getUrl → return string URL
   - R2StorageAdapter upload → Storage::fake('r2') + assertFileExists
   - R2StorageAdapter getUrl dengan R2_PUBLIC_URL → URL contains public domain
   - R2StorageAdapter delete → Storage::fake assertions
   - StorageProviderInterface binding → NullAdapter default (no R2 config in test)
```

---

**QA PROMPT:**
```
1. php artisan test --filter='StorageAdapterTest' → pass?
2. Tinker:
   >>> $storage = app(StorageProviderInterface::class)
   >>> get_class($storage) // NullStorageAdapter (default)?
   >>> $storage->upload('test content', 'invoices/test.txt') // tidak throw?
3. Storage::fake('r2') bekerja di test?
4. config/filesystems.php: disk 'r2' terdaftar (php artisan tinker config('filesystems.disks.r2'))?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.4.
Files: NullStorageAdapter, R2StorageAdapter, StorageServiceProvider,
       config/filesystems.php (updated), .env.example (updated), StorageAdapterTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: R2StorageAdapter — StorageProviderInterface impl, Cloudflare R2/S3-compat"
```

---

### SUB-TASK 6.5 — PDF Invoice Generation + Send via WA
**Status:** [ ] TODO
**Depends On:** 6.4 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. app/Modules/Invoice/Services/InvoiceService.php — method send()
2. app/Modules/Invoice/Models/Invoice.php — fields: invoice_number, amount, due_date
3. app/Modules/Shared/Contracts/StorageProviderInterface.php (dari 6.4)
4. app/Modules/WhatsApp/Adapters/WhatsAppGatewayAdapter.php — sendFile()
5. CLAUDE.md — PolicyKey: INVOICE_MAX_RESEND
6. Tenant settings: bank_name, bank_account_number, bank_account_name (dari TenantSettings)

Package: barryvdh/laravel-dompdf
Install: composer require barryvdh/laravel-dompdf
→ Publish config: php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"

Template Blade untuk invoice PDF — minimal tapi profesional:
- Logo tenant (jika ada)
- Nomor invoice, tanggal, jatuh tempo
- Data booking (event_date, event_type, package)
- Nama + telepon customer
- Amount (dp atau pelunasan)
- Info pembayaran bank (dari tenant_settings)
- Catatan jika ada

Konfirmasi:
- PDF di-store ke R2 dulu lalu URL-nya dikirim, atau kirim stream langsung?
  (Store ke R2 dulu, simpan path di invoice.pdf_url, lalu sendFile ke WA)
- Apakah invoice.pdf_url perlu kolom baru? Ya — tambah migration.
```

---

**CODING PROMPT:**
```
Buat PDF invoice generation dan update send flow.

1. Migration: add_pdf_url_to_invoices_table (timestamp baru):
   ALTER TABLE invoices ADD COLUMN pdf_url varchar(500) nullable;

2. Update Invoice model:
   Cast: (tidak perlu cast khusus, sudah ada)
   Field pdf_url tambahkan ke $fillable

3. Blade template: resources/views/pdf/invoice.blade.php
   Layout minimal tapi clean:
   - Header: nama tenant + logo (optional)
   - Section invoice info: nomor, tanggal issue, jatuh tempo, status
   - Section customer: nama, telepon (masked)
   - Section booking detail: event_date, event_type, location, package_name
   - Section amount: total, dp_amount, jenis pembayaran (DP/PELUNASAN)
   - Section bank: bank_name, rekening, atas nama (dari tenant_settings)
   - Section notes: invoice.notes jika ada
   - Footer: "Terima kasih atas kepercayaan Anda"
   Design: hitam putih, tanpa warna, agar aman di semua printer

4. InvoicePdfService (app/Modules/Invoice/Services/):
   Constructor: inject PDF (DomPDF facade), StorageProviderInterface

   generate(Invoice $invoice): string
     → Load booking + tenant + package via eager load
     → Ambil tenant_settings (bank_name, bank_account_number, bank_account_name)
     → Render view 'pdf.invoice' dengan data invoice
     → $pdf = PDF::loadView('pdf.invoice', $data)->setPaper('a4')
     → $path = 'invoices/' . $invoice->tenant_id . '/' . $invoice->invoice_number . '.pdf'
     → $storage->upload($pdf->output(), $path)
     → $invoice->update(['pdf_url' => $storage->getUrl($path)])
     → Return pdf_url

   regenerate(Invoice $invoice): string
     → Hapus old file jika pdf_url tidak null
     → Call generate()

5. Update InvoiceService::send():
   Sebelum kirim via WA:
   → Jika invoice->pdf_url null: InvoicePdfService::generate($invoice)
   → Jika mode=pdf (default Phase 6): gateway->sendFile(invoice.pdf_url, caption=text)
   → Jika sendFile gagal: fallback ke sendText dengan text saja

6. Update InvoiceService::issue():
   Setelah Invoice dibuat:
   → Dispatch job GenerateInvoicePdfJob (async, tidak block) untuk pre-generate PDF

7. GenerateInvoicePdfJob (app/Modules/Invoice/Jobs/):
   Queue: 'default'
   handle(): InvoicePdfService::generate($invoice)
   → Catch exception: log, jangan throw (non-critical)

8. Tests (app/Modules/Invoice/Tests/InvoicePdfServiceTest.php):
   - generate: Storage::fake() + PDF mock → pdf_url tersimpan di invoice
   - generate: Blade view dirender tanpa error (assertViewHas)
   - regenerate: delete lama + generate baru
   - send (updated): Http::fake WA + Storage::fake → sendFile dipanggil dengan pdf_url
   - send fallback: jika storage null URL → sendText dipanggil
   - GenerateInvoicePdfJob: job dipatch setelah issue, handle tanpa error
```

---

**QA PROMPT:**
```
1. composer show | grep dompdf → package terinstall?
2. php artisan test --filter='InvoicePdfServiceTest' → pass?
3. Tinker (dengan Storage::fake):
   >>> $inv = Invoice::find($id)
   >>> app(InvoicePdfService::class)->generate($inv)
   >>> $inv->fresh()->pdf_url // tidak null?
4. File PDF di storage (Storage::fake) ada?
5. InvoiceService::send → Http::assertSent sendFile dengan pdf_url?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.5.
Files: migration add_pdf_url_to_invoices, resources/views/pdf/invoice.blade.php,
       InvoicePdfService, GenerateInvoicePdfJob, InvoiceService (updated),
       Invoice model (updated), InvoicePdfServiceTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: PDF invoice generation — DomPDF, R2 storage, send via WA"
```

---

### SUB-TASK 6.6 — Multi-channel Foundation + Email Channel
**Status:** [ ] TODO
**Depends On:** 6.5 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 4 (interface), Enum FeatureKey::MULTI_CHANNEL
2. app/Modules/Shared/Contracts/ChannelGatewayInterface.php — sendText, sendFile
3. app/Modules/WhatsApp/Adapters/WhatsAppGatewayAdapter.php — existing WA impl
4. app/Modules/Conversation/Models/Conversation.php — channel field?
5. app/Modules/FollowUp/Services/FollowUpService.php — sendFollowUp

Phase 6 Multi-channel target minimal:
- Conversation bisa punya channel: 'whatsapp' (default) atau 'email'
- ChannelRegistry: resolve adapter berdasarkan channel type
- EmailGatewayAdapter: kirim email via Resend (sudah ada di config)
- InvoiceService::send: jika conversation.channel='email' → pakai EmailGatewayAdapter
- FollowUpService: kirim ke channel yang sesuai conversation

Schema changes:
- conversations table: tambah kolom channel varchar(20) default 'whatsapp'
- conversations table: tambah kolom customer_email varchar(255) nullable

Konfirmasi:
- Apakah multi-channel butuh webhook inbound dari email? (Tidak di Phase 6 — outbound only)
- FeatureKey::MULTI_CHANNEL gating: jika disabled → hanya WA, tidak error
```

---

**CODING PROMPT:**
```
Buat multi-channel foundation dan Email adapter.

1. Migration: add_channel_to_conversations_table (timestamp baru):
   ALTER TABLE conversations ADD COLUMN channel varchar(20) DEFAULT 'whatsapp';
   ALTER TABLE conversations ADD COLUMN customer_email varchar(255) NULL;

2. Update Conversation model:
   Tambah 'channel', 'customer_email' ke $fillable
   Method: isEmailChannel(): bool → $this->channel === 'email'

3. ChannelType Enum (app/Modules/Shared/Enums/):
   WHATSAPP = 'whatsapp'
   EMAIL    = 'email'
   Method: label()

4. ChannelRegistry (app/Modules/Shared/Services/):
   Constructor: inject TenantFeatureService
   Singleton via provider

   getAdapter(string $channel, string $tenantId): ChannelGatewayInterface
     → Jika MULTI_CHANNEL disabled: always return WhatsAppGatewayAdapter
     → switch($channel):
         'email'     → EmailGatewayAdapter
         'whatsapp'  → WhatsAppGatewayAdapter
         default     → WhatsAppGatewayAdapter

5. EmailGatewayAdapter (app/Modules/Shared/Adapters/):
   Implements ChannelGatewayInterface

   Constructor: inject Http, config('services.resend')

   sendText(string $accountId, string $toAddress, string $text): bool
     → POST https://api.resend.com/emails
     → Headers: Authorization: Bearer {RESEND_API_KEY}
     → Body: {from: tenant_email, to: toAddress, subject: "Informasi dari ...", html: text}
     → accountId = wa_account_id (diabaikan untuk email, pakai tenant_settings.contact_email)

   sendFile(string $accountId, string $toAddress, string $fileUrl, string $caption=''): bool
     → Kirim email dengan attachment URL sebagai link (bukan binary attachment di Phase 6)
     → HTML: $caption + '<br><a href="' . $fileUrl . '">Download Invoice</a>'

6. Update InvoiceService::send():
   Inject ChannelRegistry (bukan direct ChannelGatewayInterface)
   → $channel = $invoice->booking->conversation->channel ?? 'whatsapp'
   → $to = $channel === 'email'
             ? $invoice->booking->conversation->customer_email
             : $waAccount->phone (existing logic)
   → $adapter = $channelRegistry->getAdapter($channel, $tenantId)
   → $adapter->sendText(...)

7. Update FollowUpService::sendFollowUp():
   Inject ChannelRegistry
   → Ambil channel dari conversation
   → $adapter = channelRegistry->getAdapter($channel, $tenantId)
   → $to = channel=email ? conversation->customer_email : candidate->to_phone

8. Tests (app/Modules/Shared/Tests/ChannelRegistryTest.php):
   - MULTI_CHANNEL disabled → getAdapter('email') returns WhatsAppGatewayAdapter
   - MULTI_CHANNEL enabled → getAdapter('email') returns EmailGatewayAdapter
   - MULTI_CHANNEL enabled → getAdapter('whatsapp') returns WhatsAppGatewayAdapter
   - EmailGatewayAdapter sendText → Http::fake Resend API assertSent
   - EmailGatewayAdapter sendFile → Resend assertSent dengan download link

9. Tests (tests/Feature/MultiChannelTest.php):
   - InvoiceService::send dengan conversation.channel='email' → Resend dipanggil
   - InvoiceService::send dengan conversation.channel='whatsapp' → WA gateway dipanggil
   - MULTI_CHANNEL disabled → email channel tetap pakai WA adapter
   - FollowUpService dengan email channel → Resend dipanggil
```

---

**QA PROMPT:**
```
1. php artisan test --filter='ChannelRegistryTest|MultiChannelTest' → pass?
2. php artisan migrate → conversations.channel + customer_email columns ada?
3. Tinker:
   >>> $registry = app(ChannelRegistry::class)
   >>> get_class($registry->getAdapter('whatsapp', $tid)) // WhatsAppGatewayAdapter?
   >>> get_class($registry->getAdapter('email', $tid)) // tergantung feature flag
4. MULTI_CHANNEL enabled → EmailGatewayAdapter returned untuk 'email' channel?
5. Http::fake Resend: POST /emails assertSent untuk email channel?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.6.
Files: migration add_channel_to_conversations, ChannelType enum, ChannelRegistry,
       EmailGatewayAdapter, InvoiceService (updated), FollowUpService (updated),
       Conversation model (updated), ChannelRegistryTest, MultiChannelTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: Multi-channel foundation — ChannelRegistry + Email adapter via Resend"
```

---

### SUB-TASK 6.7 — Export CSV (Bookings, Invoices, Leads)
**Status:** [ ] TODO
**Depends On:** 6.6 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. app/Filament/Tenant/Resources/BookingResource.php
2. app/Filament/Tenant/Resources/InvoiceResource.php
3. app/Modules/Lead/Models/Lead.php
4. CLAUDE.md — PRINSIP 3 (Tenant Isolation), PRINSIP 10 (Filament + Service)

Pendekatan:
- Export CSV menggunakan Filament's built-in ExportAction (Filament 3.x+)
  atau custom StreamedResponse jika lebih sederhana
- Scope: selalu per tenant — tidak boleh export data lintas tenant
- Format CSV: UTF-8 BOM agar Excel bisa buka dengan benar
- PII: customer_phone di-mask di CSV? → No, admin export → full data OK
  (admin sudah trusted, berbeda dengan log/debug)

Konfirmasi:
- Apakah export butuh async/queue? (Tidak di Phase 6 — synchronous max 1000 rows)
- Filter: export sesuai current filter di Filament table? (Yes — export apa yang ditampilkan)
```

---

**CODING PROMPT:**
```
Buat export CSV untuk Booking, Invoice, Lead.

1. ExportService (app/Modules/Shared/Services/):
   generateCsv(string $tenantId, string $model, array $filters = []): string
     → Return CSV string dengan UTF-8 BOM
     → Tenant isolation wajib (selalu where tenant_id = $tenantId)

   exportBookings(string $tenantId, array $filters = []): string
     → Columns: booking_code, customer_name, customer_phone, event_date, event_type,
                location, package_name, status, total_amount, dp_amount, notes, created_at
     → Join packages untuk package_name
     → Max 1000 rows

   exportInvoices(string $tenantId, array $filters = []): string
     → Columns: invoice_number, booking_code, type, status, amount, due_date,
                sent_count, paid_at, created_at
     → Join bookings untuk booking_code

   exportLeads(string $tenantId, array $filters = []): string
     → Columns: customer_name (dari conversation), phone (masked: +62***xxx), stage,
                temperature, created_at, last_message_at, booking_count
     → phone WAJIB di-mask di CSV (lead export berbeda dengan admin export booking
       yang memang butuh full data)
     → Subquery booking_count per conversation_id

2. ExportController (app/Http/Controllers/):
   GET /app/export/bookings?filters[status]=confirmed → StreamedResponse CSV
   GET /app/export/invoices → StreamedResponse
   GET /app/export/leads → StreamedResponse

   Response headers:
     Content-Type: text/csv; charset=UTF-8
     Content-Disposition: attachment; filename="bookings-{date}.csv"

   Auth middleware: tenant admin only, verify tenant scope

3. Routes: routes/web.php (under auth middleware group)
   Route::prefix('app/export')->middleware(['auth'])->group(function () {
       Route::get('/bookings', [ExportController::class, 'bookings'])->name('export.bookings');
       Route::get('/invoices', [ExportController::class, 'invoices'])->name('export.invoices');
       Route::get('/leads', [ExportController::class, 'leads'])->name('export.leads');
   });

4. Update Filament Resources — tambahkan HeaderAction di masing-masing:
   BookingResource table header: ExportAction (link ke export.bookings route)
   InvoiceResource table header: ExportAction (link ke export.invoices route)
   → Gunakan Filament Action custom yang redirect ke route (bukan proses di Filament)

5. Tests (app/Modules/Shared/Tests/ExportServiceTest.php):
   - exportBookings → CSV string mengandung booking_code header
   - exportBookings → row berisi data booking yang di-create
   - exportBookings tenant isolation → CSV hanya berisi data tenant sendiri
   - exportInvoices → mengandung invoice_number header
   - exportLeads → phone di-mask (+62***xxx format)
   - exportLeads → tidak include data tenant lain

6. Tests (tests/Feature/ExportControllerTest.php):
   - GET /app/export/bookings → 200, Content-Type=text/csv
   - GET /app/export/invoices → 200, CSV
   - Unauthorized (no auth) → redirect login
   - Tenant A tidak bisa download data tenant B (via manipulasi query)
```

---

**QA PROMPT:**
```
1. php artisan test --filter='ExportServiceTest|ExportControllerTest' → pass?
2. Manual:
   - GET /app/export/bookings → browser download CSV?
   - CSV bisa dibuka di Excel dengan karakter Indonesia (UTF-8 BOM)?
   - Phone di export leads ter-mask?
3. Tenant isolation: login sebagai tenant A, coba request export → hanya data A?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.7.
Files: ExportService, ExportController, routes/web.php (updated),
       BookingResource (updated), InvoiceResource (updated),
       ExportServiceTest, ExportControllerTest
KANBAN-PHASE-6.md: [x] DONE
Git commit: "feat: CSV export — bookings, invoices, leads with tenant isolation"
```

---

### SUB-TASK 6.8 — E2E Integration Test Phase 6
**Status:** [ ] TODO
**Depends On:** 6.7 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. tests/Feature/Integration/BookingFlowIntegrationTest.php (pola Phase 5)
2. app/Modules/Analytics/Services/AnalyticsService.php
3. app/Modules/Invoice/Services/InvoicePdfService.php
4. app/Modules/Calendar/Services/GoogleOAuthService.php
5. CLAUDE.md — PRINSIP 9 (MockLlmAdapter)

Skenario E2E Phase 6: analytics metrics akurat, PDF generated + stored,
Google OAuth token refresh, multi-channel email, export CSV.
```

---

**CODING PROMPT:**
```
File: tests/Feature/Integration/Phase6IntegrationTest.php

setUp():
- Seed tenant + WaAccount CONNECTED + Package + Booking CONFIRMED + Invoice PAID
- Http::fake untuk wa-gateway, google-calendar, Resend, R2 (Storage::fake)
- Carbon::setTestNow fixed date

Tests:

1. test_analytics_lead_funnel_reflects_real_data():
   → Seed 5 conversations: 3 NEW_LEAD, 2 QUALIFICATION
   → AnalyticsService::getLeadFunnel → count NEW_LEAD=3, QUALIFICATION=2
   → Percentage NEW_LEAD=60%, QUALIFICATION=40%

2. test_analytics_revenue_sums_paid_invoices_only():
   → Seed 2 invoices PAID + 1 SENT
   → getRevenue → total = sum of 2 paid only
   → SENT invoice tidak masuk total

3. test_analytics_feature_flag_basic_mode():
   → ANALYTICS_ADVANCED=false
   → getSummary → is_advanced=false, lead_funnel=[]

4. test_google_oauth_token_refresh_before_calendar_event():
   → Config calendar.provider=google
   → Seed expired token di tenant_settings
   → Http::fake: refresh endpoint return new token
   → Http::fake: google calendar createEvent return 'evt-new'
   → BookingService::confirm → token di-refresh dulu, calendar event dibuat
   → booking.calendar_event_id = 'evt-new'
   → Http::assertSent ke oauth2.googleapis.com/token (refresh)

5. test_pdf_invoice_generated_and_sent_via_wa():
   → Storage::fake()
   → Invoice::issue + InvoiceService::send
   → Storage assertExists invoice PDF
   → invoice.pdf_url tersimpan
   → Http::assertSent dispatch dengan message_type=document + pdf_url

6. test_email_channel_invoice_sent_via_resend():
   → Conversation channel='email', customer_email='test@example.com'
   → MULTI_CHANNEL=true
   → InvoiceService::send
   → Http::assertSent POST https://api.resend.com/emails, to=test@example.com

7. test_multi_channel_disabled_uses_wa_regardless():
   → Conversation channel='email'
   → MULTI_CHANNEL=false
   → InvoiceService::send
   → Http::assertSent WA dispatch (NOT Resend)

8. test_export_booking_csv_correct_columns():
   → Seed 3 bookings untuk tenant
   → GET /app/export/bookings → CSV
   → CSV rows = 3 (+ header)
   → Mengandung 'booking_code', 'customer_name' headers
   → Tidak mengandung data booking tenant lain
```

---

**QA PROMPT:**
```
1. php artisan test --filter='Phase6IntegrationTest' → semua pass?
2. Analytics accuracy verified dengan real data?
3. PDF generated + stored tanpa error?
4. Email channel flow end-to-end (Http::fake Resend)?
5. Export CSV tenant isolation verified?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 6.8.
KANBAN-PHASE-6.md: [x] DONE
Git commit: "test: Phase6IntegrationTest — analytics/pdf/oauth/multichannel/export E2E"
```

---

### INTEGRATION CHECKPOINT — PHASE 6
**Status:** [ ] TODO

**PROMPT:**
```
Integration Checkpoint akhir Phase 6.

Jalankan semua verifikasi:

1. make fresh → tidak ada error (termasuk migration baru Phase 6)
2. make test → 100% pass (target: Phase 5 (529) + 80+ test baru Phase 6 = 600+)
3. docker compose ps → semua 10 container running

4. Analytics:
   [ ] AnalyticsService::getLeadFunnel → akurat per stage
   [ ] AnalyticsService::getRevenue → hanya PAID invoices masuk
   [ ] AnalyticsService::getConversion → rate calculation benar
   [ ] ANALYTICS_ADVANCED disabled → basic mode (is_advanced=false)
   [ ] Tenant isolation: metric tidak campur antar tenant
   [ ] Filament /app/analytics → 200, stats ditampilkan

5. Google OAuth 2.0:
   [ ] GET /app/calendar/oauth/redirect → 302 ke Google
   [ ] exchangeCode → token disimpan ke tenant_settings
   [ ] Token refresh auto sebelum expired (<5 menit)
   [ ] CalendarSettingsPage → Connected/Not Connected status benar
   [ ] revokeToken → tenant_settings.google_oauth_token cleared

6. R2 Storage:
   [ ] StorageProviderInterface binding → NullAdapter default (no R2 config)
   [ ] R2StorageAdapter upload + getUrl dengan Storage::fake → OK
   [ ] config/filesystems.php disk 'r2' terdaftar

7. PDF Invoice:
   [ ] InvoicePdfService::generate → PDF file dibuat, pdf_url tersimpan
   [ ] Blade template invoice.blade.php render tanpa error
   [ ] InvoiceService::send → sendFile dengan pdf_url (bukan sendText)
   [ ] GenerateInvoicePdfJob dispatched saat issue

8. Multi-channel:
   [ ] ChannelRegistry → WhatsApp default, Email jika MULTI_CHANNEL=true
   [ ] EmailGatewayAdapter → POST Resend API assertSent
   [ ] InvoiceService::send channel=email → Resend dipanggil
   [ ] FollowUpService channel=email → Resend dipanggil
   [ ] MULTI_CHANNEL disabled → selalu WA adapter

9. Export CSV:
   [ ] GET /app/export/bookings → 200, Content-Type=text/csv
   [ ] GET /app/export/invoices → 200
   [ ] GET /app/export/leads → phone di-mask
   [ ] Tenant isolation export
   [ ] Unauthorized → redirect login

10. E2E Phase 6:
    [ ] Phase6IntegrationTest semua 8 test PASS
    [ ] Analytics + PDF + OAuth + email channel + export semua verified

11. Semua PRINSIP Phase 1-5 masih terjaga:
    [ ] PRINSIP 3 — Tenant isolation di analytics, export, multi-channel
    [ ] PRINSIP 4 — StorageProviderInterface, ChannelRegistry, CalendarProviderInterface
    [ ] PRINSIP 9 — MockLlmAdapter di semua unit test LLM
    [ ] PRINSIP 14 — Concurrent booking lock masih OK (regression)
    [ ] Phone masking di export leads (security)

Exit Gate Checklist:
[ ] php artisan test → 100% PASS (600+ tests)
[ ] Analytics KPI verified dengan real seeded data
[ ] Google OAuth full flow (Http::fake) verified
[ ] PDF invoice generated + stored + sent
[ ] Email channel working (MULTI_CHANNEL enabled)
[ ] Export CSV dengan tenant isolation
[ ] Phase6IntegrationTest semua pass

Jika semua PASS:
1. Update PROGRESS.md: Checkpoint Phase 6 DONE, Gate OPEN
2. Tulis CATATAN PENTING ANTAR SUB-TASK Phase 6:
   - Analytics: query pattern yang efisien
   - OAuth: token storage format di tenant_settings
   - PDF: DomPDF config dan template path
   - Storage: R2 disk config format
   - Multi-channel: ChannelRegistry pattern untuk Phase 7+
   - Hal penting untuk Phase 7 (Production hardening, Benchmark, Launch prep)
3. Buat git tag:
   git tag -a v0.7-analytics-complete -m "Analytics/OAuth/PDF/Storage/Multi-channel complete. Tests: X pass."
4. Lanjut ke KANBAN-PHASE-7.md (Production hardening, Benchmark 30 scenarios, Launch)
```
