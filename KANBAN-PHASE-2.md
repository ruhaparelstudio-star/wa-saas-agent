# KANBAN-PHASE-2.md — Knowledge & Settings
# PRE-REQUISITE: Phase 1 gate harus OPEN di PROGRESS.md
# Target: Knowledge base siap, tenant config berjalan, Filament panel lengkap
# Exit Gate: php artisan test 100% pass, KnowledgeRetrieverService bisa query data

---

## CEK SEBELUM MULAI

```
Buka PROGRESS.md, pastikan:
[ ] Integration Checkpoint Phase 1 → Gate: OPEN
[ ] Git tag v0.2-foundation-complete sudah ada
[ ] 66 tests passing dari Phase 1

Jika belum: selesaikan Phase 1 dulu.
```

---

### SUB-TASK 2.1 — Migration Knowledge Tables
**Status:** [x] DONE — 2026-05-14
**Depends On:** Phase 1 gate OPEN
**Estimated Time:** 1 jam

---

**CONTEXT PROMPT:**
```
Baca sebelum mulai:
1. CLAUDE.md — PRINSIP 3 (Tenant Isolation), PRINSIP 5 (UUID), PRINSIP 11 (Timezone)
2. PROGRESS.md — cek Phase 1 checkpoint OPEN, File Registry
3. app/Modules/Shared/Enums/AssetType.php — AssetType cases
4. app/Modules/Shared/Enums/PolicyKey.php — PolicyKey cases
5. app/Modules/Shared/Enums/TenantTone.php — TenantTone cases
6. app/Modules/Shared/Models/TenantBaseModel.php — cek implementasinya
7. app/Modules/Tenancy/Models/Tenant.php — cek relasi yang ada

Depends on check:
[ ] app/Modules/Shared/Models/TenantBaseModel.php ada
[ ] app/Modules/Shared/Enums/AssetType.php ada (4 cases)
[ ] app/Modules/Shared/Enums/PolicyKey.php ada (6 cases)
[ ] app/Modules/Shared/Enums/TenantTone.php ada (4 cases)

Konfirmasi:
- Kenapa semua tabel Knowledge extend TenantBaseModel?
- Apa bedanya tenant_settings dan tenant_policies?
- Kenapa package_prices punya valid_from dan valid_until?
  (hint: vendor wedding sering naik harga musim peak)
```

---

**CODING PROMPT:**
```
Buat 7 migration untuk Knowledge & Settings module.
Semua di laravel-app/database/migrations/ dengan timestamp berurutan.

PENTING: Semua tabel (kecuali tenant_settings) menggunakan tenant_id FK.
tenant_settings adalah 1-to-1 dengan tenants.

1. create_packages_table (2026_05_12_000001):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   name varchar(255)
   slug varchar(255)
   description text nullable
   category varchar(100) default 'wedding'
   is_active boolean default true
   sort_order int default 0
   timestamps
   UNIQUE: [tenant_id, slug]
   INDEX: [tenant_id, is_active]

2. create_package_prices_table (2026_05_12_000002):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   package_id uuid FK packages (cascade delete)
   label varchar(100)  — contoh: "Weekday", "Weekend", "Peak Season"
   price_idr bigint    — harga dalam IDR (integer, bukan decimal)
   valid_from date
   valid_until date nullable  — null = berlaku selamanya
   notes text nullable
   is_active boolean default true
   timestamps
   INDEX: [package_id, valid_from, valid_until]
   INDEX: [tenant_id]

3. create_faqs_table (2026_05_12_000003):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   question varchar(500)
   answer text
   category varchar(100) nullable  — contoh: "booking", "harga", "proses"
   is_active boolean default true
   sort_order int default 0
   search_vector tsvector nullable  — untuk full-text search, diisi via DB trigger/manual
   timestamps
   INDEX: [tenant_id, is_active]
   INDEX: GIN search_vector (untuk tsvector search)

4. create_knowledge_items_table (2026_05_12_000004):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   title varchar(255)
   content text
   category varchar(100)  — contoh: "terms", "process", "tips", "policy"
   tags jsonb default '[]'
   is_active boolean default true
   search_vector tsvector nullable
   timestamps
   INDEX: [tenant_id, category, is_active]
   INDEX: GIN search_vector

5. create_assets_table (2026_05_12_000005):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   type varchar(50)  — pakai AssetType enum values
   name varchar(255)
   file_path varchar(500)   — path di storage
   file_url varchar(500) nullable  — public URL jika ada
   mime_type varchar(100)
   file_size_kb int default 0
   is_active boolean default true
   timestamps
   INDEX: [tenant_id, type, is_active]

6. create_tenant_settings_table (2026_05_12_000006):
   id uuid PK
   tenant_id uuid UNIQUE FK tenants (cascade delete)  — 1-to-1
   tone varchar(50) default 'semi_formal'   — TenantTone values
   timezone varchar(100) default 'Asia/Jakarta'
   business_hours_start varchar(5) default '08:00'   — format HH:MM
   business_hours_end varchar(5) default '21:00'
   business_days jsonb default '[1,2,3,4,5,6]'   — 1=Senin, 7=Minggu
   after_hours_message text nullable
   timestamps

7. create_tenant_policies_table (2026_05_12_000007):
   id uuid PK
   tenant_id uuid FK tenants (cascade delete)
   policy_key varchar(100)   — PolicyKey enum values
   policy_value varchar(500)
   timestamps
   UNIQUE: [tenant_id, policy_key]
   INDEX: [tenant_id]

Setelah migration dibuat:
docker compose exec app php artisan migrate
→ semua 7 migration berjalan tanpa error
```

---

**QA PROMPT:**
```
Verifikasi:
1. docker compose exec app php artisan migrate → tidak ada error?
2. docker compose exec app php artisan tinker
   >>> Schema::hasTable('packages') // true?
   >>> Schema::hasTable('faqs') // true?
   >>> Schema::hasTable('tenant_settings') // true?
3. Cek UNIQUE constraint:
   >>> Schema::getIndexes('packages')
   → ada index di [tenant_id, slug]?
4. Cek tsvector column:
   >>> Schema::getColumns('faqs')
   → ada kolom search_vector?
5. Cek cascade delete:
   >>> Buat tenant, tambah package, delete tenant → package ikut terhapus?
6. Total tabel setelah migrate: berapa? (target: 17 tabel termasuk Phase 1)
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.1:
- Status: [x] DONE
- Tables Created: packages, package_prices, faqs, knowledge_items,
                 assets, tenant_settings, tenant_policies
- Migration Count: 7

File Registry tambahkan 7 migration file.

KANBAN-PHASE-2.md: [x] DONE

Git:
git add database/migrations/ PROGRESS.md KANBAN-PHASE-2.md
git commit -m "feat: migration knowledge & settings tables (7 tabel)"
```

---

### SUB-TASK 2.2 — Models + PackageResolver + PriceResolver
**Status:** [x] DONE — 2026-05-14
**Depends On:** 2.1 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 3, PRINSIP 5, WEDDING ENTITY SCHEMA (package_slug, package_interest)
2. PROGRESS.md — cek File Registry, pastikan 7 migration ada
3. app/Modules/Shared/Models/TenantBaseModel.php
4. app/Modules/Shared/Models/BaseModel.php
5. app/Modules/Shared/Contracts/KnowledgeRetrieverInterface.php
6. app/Modules/Shared/DTOs/GroundedKnowledgeDTO.php

Depends on check:
[ ] packages tabel ada
[ ] package_prices tabel ada

Konfirmasi:
- Kenapa PriceResolver perlu getActivePrice(packageId, date)?
  (hint: PRINSIP 15 — billing date, peak season pricing)
- Apa yang dimaksud package_slug vs package_interest di Wedding Entity Schema?
```

---

**CODING PROMPT:**
```
Buat Knowledge module. Jalankan: php artisan module:make Knowledge

1. Models (semua di app/Modules/Knowledge/Models/):

   Package.php (extend TenantBaseModel):
     Cast: is_active → boolean, sort_order → integer
     Relations:
       prices(): hasMany(PackagePrice)
       activePrices(): hasMany(PackagePrice)->where('is_active', true)
     Scope: active($query) → where('is_active', true)->orderBy('sort_order')
     Method: getActivePriceForDate(Carbon $date): ?PackagePrice
       → cari price dengan valid_from <= date AND (valid_until >= date OR valid_until IS NULL)
       → jika lebih dari 1 match, ambil yang valid_from paling baru
       → return null jika tidak ada

   PackagePrice.php (extend TenantBaseModel):
     Cast: price_idr → integer, valid_from → date, valid_until → date, is_active → boolean
     Relations: package(): belongsTo(Package)
     Method: isValidOnDate(Carbon $date): bool
       → valid_from <= $date AND (valid_until IS NULL OR valid_until >= $date)
       → AND is_active = true
     Accessor: formatted_price: rupiah format (Rp 15.000.000)

   Faq.php (extend TenantBaseModel):
     Cast: is_active → boolean, sort_order → integer
     Relations: -
     Scope: active($query)
     Scope: byCategory($query, string $category)

   KnowledgeItem.php (extend TenantBaseModel):
     Cast: tags → array, is_active → boolean
     Scope: active($query)
     Scope: byCategory($query, string $category)

   Asset.php (extend TenantBaseModel):
     Cast: type → AssetType, is_active → boolean
     Relations: -
     Scope: active($query)
     Scope: byType($query, AssetType $type)
     Accessor: human_file_size: "1.2 MB" format

2. PackageResolver.php (app/Modules/Knowledge/Services/):
   Constructor: inject via service container

   getActivePackages(string $tenantId): Collection
     → Package::where('tenant_id', $tenantId)->active()->with('activePrices')->get()
     → Cache 10 menit di Redis per tenant_id

   getPackageDetail(string $tenantId, string $slug): ?Package
     → Package::where('tenant_id', $tenantId)->where('slug', $slug)
       ->active()->with('activePrices')->first()

   matchByName(string $tenantId, string $rawName): ?Package
     → Case-insensitive search di name dan slug
     → Coba exact match dulu, lalu ILIKE match
     → Return null jika tidak ditemukan
     → Cache 5 menit

   invalidateCache(string $tenantId): void
     → Hapus semua cache key untuk tenant ini

3. PriceResolver.php (app/Modules/Knowledge/Services/):

   getActivePrice(string $packageId, Carbon $date): ?PackagePrice
     → Cari PackagePrice yang valid pada date tersebut
     → is_active = true, valid_from <= date, (valid_until IS NULL OR valid_until >= date)
     → Jika ada beberapa, ambil valid_from paling baru
     → Cache 30 menit per [package_id + date]

   getLowestCurrentPrice(string $tenantId): ?PackagePrice
     → Ambil harga terendah dari semua paket aktif tenant saat ini (Carbon::today())
     → Untuk reply "harga mulai dari Rp X"

   getPriceRange(string $tenantId): array
     → Return ['min' => PackagePrice|null, 'max' => PackagePrice|null, 'date' => Carbon]

4. Tests (app/Modules/Knowledge/Tests/PackageResolverTest.php):
   - getActivePackages mengembalikan hanya paket aktif
   - getPackageDetail dengan slug yang benar → return Package
   - getPackageDetail dengan slug salah → return null
   - matchByName dengan nama exact → match
   - matchByName dengan nama typo ringan → match (ILIKE)
   - matchByName dengan nama tidak ada → null
   - getActivePrice dengan date valid → return price
   - getActivePrice dengan expired price (valid_until kemarin) → return null
   - getLowestCurrentPrice → return harga terendah
   - Cache bekerja: panggil 2x, DB query hanya 1x
   - Tenant isolation: paket tenant A tidak muncul untuk tenant B
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter=PackageResolverTest → semua pass?
2. Tinker test:
   - Buat tenant + package + price dengan valid_until kemarin
   - PriceResolver::getActivePrice(packageId, today()) → null?
   - Buat price baru dengan valid_from hari ini, valid_until null
   - PriceResolver::getActivePrice(packageId, today()) → ada?
3. Tenant isolation:
   - Buat 2 tenant dengan package masing-masing
   - PackageResolver::getActivePackages(tenantA_id) → hanya paket tenant A?
4. Cache check (sama dengan FeatureGateService): panggil 2x → DB query hanya 1x?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.2.
Methods Exposed:
  - PackageResolver::getActivePackages(tenantId)
  - PackageResolver::getPackageDetail(tenantId, slug)
  - PackageResolver::matchByName(tenantId, rawName)
  - PriceResolver::getActivePrice(packageId, date)
  - PriceResolver::getLowestCurrentPrice(tenantId)
  - PriceResolver::getPriceRange(tenantId)
File Registry: tambahkan semua model dan service baru.
KANBAN-PHASE-2.md: [x] DONE
Git commit: "feat: Knowledge models, PackageResolver, PriceResolver"
```

---

### SUB-TASK 2.3 — KnowledgeService + AssetResolver
**Status:** [x] DONE — 2026-05-14
**Depends On:** 2.2 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 4 (KnowledgeRetrieverInterface), bagian "PRINSIP 7 — Zero Black Box"
2. app/Modules/Shared/Contracts/KnowledgeRetrieverInterface.php
3. app/Modules/Shared/DTOs/GroundedKnowledgeDTO.php
4. app/Modules/Shared/DTOs/GroundingRefDTO.php
5. app/Modules/Knowledge/Services/PackageResolver.php
6. app/Modules/Knowledge/Services/PriceResolver.php

Depends on check:
[ ] Faq model ada
[ ] KnowledgeItem model ada
[ ] Asset model ada
[ ] KnowledgeRetrieverInterface ada

Konfirmasi:
- Apa fields yang wajib ada di GroundingRefDTO?
- Kenapa KnowledgeRetrieverService perlu return GroundedKnowledgeDTO dan bukan array biasa?
  (hint: PRINSIP 7, Phase 3 decision trace logging)
```

---

**CODING PROMPT:**
```
Buat KnowledgeService dan AssetResolver di app/Modules/Knowledge/Services/.

1. KnowledgeService.php:

   getFaqsByCategory(string $tenantId, ?string $category = null): Collection
     → Faq::active()->where('tenant_id', $tenantId)
     → Jika $category tidak null: ->byCategory($category)
     → orderBy sort_order

   searchFaqs(string $tenantId, string $query, int $limit = 5): Collection
     → Pertama coba tsvector search:
       DB::raw("search_vector @@ plainto_tsquery('indonesian', ?)", [$query])
       → ORDER BY ts_rank(search_vector, plainto_tsquery('indonesian', ?)) DESC
     → Jika tsvector search kosong atau search_vector null: fallback ke ILIKE
       where('question', 'ILIKE', "%{$query}%")
       ->orWhere('answer', 'ILIKE', "%{$query}%")
     → limit($limit)
     → Hanya return yang is_active = true

   searchKnowledgeItems(string $tenantId, string $query, int $limit = 5): Collection
     → Sama seperti searchFaqs tapi ke knowledge_items table
     → Search di title dan content

   getFaqAsGroundingRefs(Collection $faqs): array
     → Map setiap Faq ke GroundingRefDTO:
       type: 'structured', source: 'faqs'
       id: faq->id, key_data: faq->question

2. AssetResolver.php:

   getActivePricelist(string $tenantId): ?Asset
     → Asset::active()->where('tenant_id', $tenantId)
       ->byType(AssetType::PRICELIST)->latest()->first()

   getAssetsByType(string $tenantId, AssetType $type): Collection
     → Asset::active()->where('tenant_id', $tenantId)->byType($type)->get()

   getPricelistUrl(string $tenantId): ?string
     → getActivePricelist → return file_url ?? Storage::url(file_path)
     → null jika tidak ada pricelist aktif

3. KnowledgeRetrieverService.php:
   CATATAN: ini adalah implementasi STUB untuk Phase 2.
   Full implementation (dengan tsvector + pgvector ranking) ada di Phase 3.
   Implements KnowledgeRetrieverInterface.

   retrieve(string $intent, array $entities, string $tenantId): GroundedKnowledgeDTO
     → Panggil PackageResolver::getActivePackages(tenantId) → structured_data['packages']
     → Jika $intent mengandung 'price' atau 'harga':
       - Panggil PriceResolver::getPriceRange(tenantId) → structured_data['price_range']
     → Jika ada entities['package_interest']:
       - Panggil PackageResolver::matchByName → structured_data['matched_package']
     → Panggil KnowledgeService::searchFaqs(tenantId, join($entities, ' ')) → structured_data['faqs']
     → Build grounding_refs dari semua data yang diambil
     → Return GroundedKnowledgeDTO::from([
         'structured_data' => [...],
         'vector_results' => [],  // Phase 3
         'grounding_refs' => [...],
         'search_method' => 'tsvector'
       ])

   PENTING: Bind di KnowledgeServiceProvider:
   $this->app->bind(KnowledgeRetrieverInterface::class, KnowledgeRetrieverService::class);

4. KnowledgeServiceProvider.php (di app/Modules/Knowledge/):
   - Register: bind KnowledgeRetrieverInterface → KnowledgeRetrieverService
   - Boot: load routes.php

5. Tests (app/Modules/Knowledge/Tests/KnowledgeServiceTest.php):
   - searchFaqs dengan query yang ada → return FAQ yang relevan
   - searchFaqs dengan query kosong → return top FAQs
   - searchFaqs tenant A tidak return FAQ tenant B
   - getActivePricelist → return asset tipe PRICELIST
   - KnowledgeRetrieverService::retrieve → return GroundedKnowledgeDTO valid
   - GroundedKnowledgeDTO memiliki grounding_refs yang tidak kosong
   - retrieve dengan intent 'ask_price' → structured_data memiliki 'price_range'
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter=KnowledgeServiceTest → semua pass?
2. Tinker test:
   >>> $retriever = app(App\Modules\Shared\Contracts\KnowledgeRetrieverInterface::class)
   >>> $result = $retriever->retrieve('ask_price', [], $tenantId)
   >>> $result->search_method // 'tsvector'
   >>> count($result->grounding_refs) // > 0?
3. Binding test:
   >>> app()->bound(App\Modules\Shared\Contracts\KnowledgeRetrieverInterface::class)
   // true?
4. tsvector fallback test: buat FAQ tanpa search_vector → searchFaqs masih bekerja via ILIKE?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.3.
Methods Exposed:
  - KnowledgeService::searchFaqs(tenantId, query, limit)
  - KnowledgeService::searchKnowledgeItems(tenantId, query, limit)
  - KnowledgeService::getFaqsByCategory(tenantId, category)
  - AssetResolver::getActivePricelist(tenantId)
  - AssetResolver::getPricelistUrl(tenantId)
  - KnowledgeRetrieverService::retrieve(intent, entities, tenantId) [STUB]

CATATAN PENTING: KnowledgeRetrieverService adalah STUB.
Full implementation dengan tsvector ranking dan pgvector ada di Phase 3 (sub-task 3.X).
Semua unit test Phase 2 pakai STUB, tidak call real DB untuk vector search.

KANBAN-PHASE-2.md: [x] DONE
Git commit: "feat: KnowledgeService, AssetResolver, KnowledgeRetrieverService stub"
```

---

### SUB-TASK 2.4 — Full-text Search Setup (tsvector)
**Status:** [x] DONE — 2026-05-14
**Depends On:** 2.3 selesai
**Estimated Time:** 1 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — ARCH-004 (Full-text search untuk MVP)
2. DECISIONS.md — ARCH-003 dan ARCH-004
3. app/Modules/Knowledge/Services/KnowledgeService.php — cek searchFaqs logic
4. app/Modules/Knowledge/Models/Faq.php
5. app/Modules/Knowledge/Models/KnowledgeItem.php

Depends on check:
[ ] faqs table ada dengan kolom search_vector (tsvector)
[ ] knowledge_items table ada dengan kolom search_vector

Konfirmasi:
- Bagaimana tsvector di PostgreSQL bekerja?
- Apa perbedaan plainto_tsquery dan to_tsquery?
- Kenapa pakai 'indonesian' language config (bukan 'english')?
  (Hint: stopwords dan stemming berbeda)
```

---

**CODING PROMPT:**
```
Setup tsvector indexing untuk knowledge base.

PENTING: PostgreSQL 'indonesian' language config mungkin belum ada di Alpine image.
Fallback: gunakan 'simple' config yang bekerja tanpa language-specific stemming.

1. Migration untuk DB function + trigger (2026_05_12_000008):

   Buat migration yang jalankan raw SQL:

   a. Function update_faq_search_vector():
      CREATE OR REPLACE FUNCTION update_faq_search_vector()
      RETURNS TRIGGER AS $$
      BEGIN
        NEW.search_vector := to_tsvector('simple', coalesce(NEW.question,'') || ' ' || coalesce(NEW.answer,''));
        RETURN NEW;
      END;
      $$ LANGUAGE plpgsql;

   b. Trigger di faqs table:
      DROP TRIGGER IF EXISTS faqs_search_vector_trigger ON faqs;
      CREATE TRIGGER faqs_search_vector_trigger
        BEFORE INSERT OR UPDATE ON faqs
        FOR EACH ROW EXECUTE FUNCTION update_faq_search_vector();

   c. Function update_knowledge_item_search_vector():
      (sama, untuk knowledge_items: title || ' ' || content || ' ' || category)

   d. Trigger di knowledge_items table (sama pattern)

   e. Update existing rows (untuk seeder nanti):
      UPDATE faqs SET search_vector = to_tsvector('simple', coalesce(question,'') || ' ' || coalesce(answer,''));
      UPDATE knowledge_items SET search_vector = to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(content,'') || ' ' || coalesce(category,''));

   Down: DROP TRIGGER IF EXISTS..., DROP FUNCTION IF EXISTS...

2. Update KnowledgeService.php:
   Ganti 'indonesian' → 'simple' di plainto_tsquery dan ts_rank

3. Tests (app/Modules/Knowledge/Tests/TsvectorSearchTest.php):
   - Buat FAQ dengan question "paket foto outdoor" → search "outdoor" harus ketemu
   - Buat FAQ dengan question "harga pernikahan 2024" → search "harga" harus ketemu
   - Search kata tidak ada → return Collection kosong (fallback ke ILIKE masih ada)
   - Update FAQ question → search_vector ter-update otomatis via trigger
   - Insert FAQ baru → search_vector auto-filled (tidak perlu manual set)

4. Command untuk rebuild search vectors (opsional tapi recommended):
   app/Console/Commands/RebuildSearchVectorsCommand.php
   Signature: knowledge:rebuild-search-vectors {--tenant=}
   → Update semua search_vector untuk faqs dan knowledge_items
   → Output: "Rebuilt X vectors for Y tenants"
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan migrate → migration berjalan?
2. php artisan test --filter=TsvectorSearch → semua pass?
3. Tinker test:
   - Seed 1 FAQ manual:
     >>> Faq::create(['tenant_id' => $id, 'question' => 'Berapa paket harga foto wedding?', 'answer' => 'Mulai dari 5 juta', 'is_active' => true])
   - Langsung check search_vector:
     >>> DB::select("SELECT search_vector FROM faqs LIMIT 1")
     → search_vector tidak null?
   - Search:
     >>> DB::select("SELECT * FROM faqs WHERE search_vector @@ plainto_tsquery('simple', 'harga')")
     → FAQ di atas muncul?
4. php artisan knowledge:rebuild-search-vectors → tidak error?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.4.
Notes: tsvector menggunakan 'simple' config (bukan 'indonesian') karena
       PostgreSQL Alpine tidak include Indonesian stemming dict.
       'simple' bekerja baik untuk bahasa Indonesia tanpa stopwords removal.

KANBAN-PHASE-2.md: [x] DONE
Git commit: "feat: tsvector full-text search setup, trigger, RebuildSearchVectorsCommand"
```

---

### SUB-TASK 2.5 — Tenant Settings + Policy + Business Hours
**Status:** [ ] TODO
**Depends On:** 2.4 selesai
**Estimated Time:** 1.5 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 11 (Timezone), PolicyKey enum, TenantConfigDTO fields
2. app/Modules/Shared/DTOs/TenantConfigDTO.php — cek semua field yang harus diisi
3. app/Modules/Shared/Enums/PolicyKey.php — 6 keys beserta value defaultnya
4. app/Modules/Shared/Enums/TenantTone.php — 4 tones
5. app/Modules/Tenancy/Models/Tenant.php

Depends on check:
[ ] tenant_settings tabel ada
[ ] tenant_policies tabel ada
[ ] TenantConfigDTO ada dengan field: tenant_id, tone, timezone, business_hours_start,
    business_hours_end, policies, features

Konfirmasi:
- Apa nilai default untuk setiap PolicyKey?
  (PRICELIST_MODE default: 'public', AFTER_HOURS_BEHAVIOR default: 'queue', dll)
- Kenapa TenantConfigResolver perlu cache?
  (hint: dipanggil setiap turn AI pipeline di Phase 3)
- Bagaimana BusinessHoursService handle timezone? (PRINSIP 11)
```

---

**CODING PROMPT:**
```
Buat TenantConfig module. Jalankan: php artisan module:make TenantConfig

1. Models (app/Modules/TenantConfig/Models/):

   TenantSetting.php (extend BaseModel — bukan TenantBaseModel, karena relasi 1-to-1 ke tenant):
     Cast: tone → TenantTone, business_days → array
     Relations: tenant(): belongsTo(Tenant)
     Method: isBusinessDay(Carbon $date): bool
       → cek $date->dayOfWeek (1=Monday, 7=Sunday) ada di business_days array

   TenantPolicy.php (extend BaseModel):
     Cast: policy_key → PolicyKey
     Relations: tenant(): belongsTo(Tenant)

2. PolicyDefaults — const/helper (app/Modules/TenantConfig/Support/PolicyDefaults.php):
   const DEFAULTS = [
     PolicyKey::PRICELIST_MODE->value             => 'public',
     PolicyKey::PRICELIST_MIN_REQUIREMENT->value  => '0',
     PolicyKey::LEAD_LIMIT_FALLBACK->value        => 'queue',
     PolicyKey::AFTER_HOURS_BEHAVIOR->value       => 'queue',
     PolicyKey::INVOICE_MAX_RESEND->value         => '3',
     PolicyKey::CONCURRENT_BOOKING_LOCK->value    => 'true',
   ];

   static function getDefault(PolicyKey $key): string
   static function all(): array

3. TenantConfigResolver.php (app/Modules/TenantConfig/Services/):

   resolve(string $tenantId): TenantConfigDTO
     → Ambil TenantSetting (atau buat default jika belum ada)
     → Ambil semua TenantPolicy untuk tenant ini
     → Ambil FeatureGateService results untuk features
     → Cache 5 menit di Redis per tenant_id
     → Return TenantConfigDTO

   get(string $tenantId, string $key, mixed $default = null): mixed
     → resolve($tenantId) → return field dari DTO atau policies array
     → Helper untuk akses single value

   invalidateCache(string $tenantId): void

4. BusinessHoursService.php (app/Modules/TenantConfig/Services/):

   isOpen(string $tenantId, ?Carbon $datetime = null): bool
     → $datetime ??= Carbon::now('UTC')
     → Ambil TenantSetting (atau default)
     → Convert $datetime ke timezone tenant: $local = $datetime->copy()->setTimezone($setting->timezone)
     → Cek isBusinessDay($local)
     → Cek $local->format('H:i') antara business_hours_start dan business_hours_end
     → Return bool

   getNextOpenTime(string $tenantId, ?Carbon $from = null): Carbon
     → Hitung kapan jam buka berikutnya
     → Jika hari ini masih buka: return hari ini jam business_hours_start
     → Jika tidak: maju ke hari berikutnya yang merupakan business_day
     → Return Carbon dalam UTC

   getAfterHoursBehavior(string $tenantId): string
     → Ambil policy AFTER_HOURS_BEHAVIOR (default: 'queue')

5. TenantPolicyService.php (app/Modules/TenantConfig/Services/):

   getPolicy(string $tenantId, PolicyKey $key): string
     → Cari di tenant_policies, jika tidak ada return PolicyDefaults::getDefault($key)

   getPolicies(string $tenantId): array
     → Return semua policies sebagai [key => value] array
     → Merge default values untuk keys yang belum di-set

   setPolicy(string $tenantId, PolicyKey $key, string $value): void
     → updateOrCreate di tenant_policies

6. Tambahkan relasi ke Tenant model:
   settings(): hasOne(TenantSetting::class)
   policies(): hasMany(TenantPolicy::class)

7. Tests (app/Modules/TenantConfig/Tests/TenantConfigTest.php):
   - TenantConfigResolver::resolve → return TenantConfigDTO valid
   - Default timezone = 'Asia/Jakarta' jika tenant belum punya settings
   - BusinessHoursService::isOpen saat jam buka → true
   - BusinessHoursService::isOpen saat jam tutup → false
   - BusinessHoursService::isOpen di hari Minggu (jika tidak ada di business_days) → false
   - TenantPolicyService::getPolicy untuk key yang belum di-set → return default
   - TenantPolicyService::setPolicy → tersimpan, getPolicy return value baru
   - Timezone handling: isOpen UTC 01:00 untuk tenant WIB (UTC+7) jam buka 08:00 → true
     (karena 01:00 UTC = 08:00 WIB)
   - Cache bekerja: resolve dipanggil 2x → query DB hanya 1x
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter=TenantConfigTest → semua pass?
2. Tinker test:
   >>> $resolver = app(App\Modules\TenantConfig\Services\TenantConfigResolver::class)
   >>> $dto = $resolver->resolve($tenantId)
   >>> $dto->timezone // 'Asia/Jakarta' (default)?
   >>> $dto->tone->value // 'semi_formal'?
   >>> $dto->business_hours_start // '08:00'?
3. BusinessHours test:
   >>> $biz = app(App\Modules\TenantConfig\Services\BusinessHoursService::class)
   >>> // Saat Senin jam 10:00 WIB → isOpen harus true
   >>> // Saat Senin jam 22:00 WIB → isOpen harus false
4. Policy default test:
   >>> $policy = app(App\Modules\TenantConfig\Services\TenantPolicyService::class)
   >>> $policy->getPolicy($tenantId, App\Modules\Shared\Enums\PolicyKey::INVOICE_MAX_RESEND)
   // '3' (default)?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.5.
Methods Exposed:
  - TenantConfigResolver::resolve(tenantId): TenantConfigDTO
  - TenantConfigResolver::get(tenantId, key, default): mixed
  - BusinessHoursService::isOpen(tenantId, datetime): bool
  - BusinessHoursService::getNextOpenTime(tenantId): Carbon
  - TenantPolicyService::getPolicy(tenantId, policyKey): string
  - TenantPolicyService::getPolicies(tenantId): array
  - TenantPolicyService::setPolicy(tenantId, policyKey, value): void

KANBAN-PHASE-2.md: [x] DONE
Git commit: "feat: TenantConfig, BusinessHoursService, TenantPolicyService, PolicyDefaults"
```

---

### SUB-TASK 2.6 — Filament Knowledge Panel (Tenant)
**Status:** [ ] TODO
**Depends On:** 2.5 selesai
**Estimated Time:** 2 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — PRINSIP 10 (Filament untuk dashboard, bukan duplikasi logic)
2. PROGRESS.md — notes dari sub-task 1.10 tentang Filament 5.x
3. app/Providers/Filament/TenantPanelProvider.php
4. app/Filament/Tenant/Pages/Dashboard.php
5. app/Modules/Knowledge/Models/Package.php
6. app/Modules/Knowledge/Models/Faq.php
7. app/Modules/Knowledge/Models/Asset.php
8. app/Modules/TenantConfig/Models/TenantSetting.php

Depends on check:
[ ] TenantPanelProvider ada di /app path
[ ] Package, Faq, KnowledgeItem, Asset models ada
[ ] TenantSetting model ada

PENTING (Filament 5.x notes dari Phase 1):
- form() menggunakan Schema, bukan Form
- Actions pakai Filament\Actions\* bukan Tables\Actions\*
- Auth check via canAccessPanel() di User model

Konfirmasi:
- Mengapa Filament resource WAJIB call Service, bukan langsung ke Model?
  (PRINSIP 10 — tidak ada logic duplikat antara Filament dan API)
```

---

**CODING PROMPT:**
```
Buat Filament resources untuk Tenant panel.
Semua di app/Filament/Tenant/.

1. PackageResource (app/Filament/Tenant/Resources/PackageResource.php):

   Table columns:
     - name (sortable, searchable)
     - prices count (badge: "X harga")
     - is_active (toggle — call Package::update langsung, simple enough)
     - sort_order
     - updated_at

   Form fields (Schema):
     - name (TextInput, required)
     - slug (TextInput, auto-generate dari name, unique per tenant)
     - description (Textarea, nullable)
     - category (Select: wedding, corporate, birthday — default wedding)
     - is_active (Toggle)
     - sort_order (TextInput, numeric)

   RelationManager: PricesRelationManager
     Table columns: label, price_idr (formatted Rp), valid_from, valid_until, is_active
     Form: label, price_idr, valid_from, valid_until, notes, is_active

   Pages: ListPackages, CreatePackage, EditPackage

   PENTING: Saat create/edit, WAJIB inject tenant_id dari auth()->user()->tenant_id
   Tenant tidak boleh lihat atau edit package tenant lain.

2. FaqResource (app/Filament/Tenant/Resources/FaqResource.php):

   Table columns: question (truncate 60 char), category, is_active, sort_order
   Form: question, answer (Textarea), category (TextInput), is_active, sort_order
   
   Actions: ReorderAction (drag-drop sort_order) — opsional jika Filament 5 support

3. KnowledgeItemResource (app/Filament/Tenant/Resources/KnowledgeItemResource.php):

   Table columns: title, category, tags (badge list), is_active
   Form: title, content (Textarea, required), category, tags (TagsInput), is_active

4. AssetResource (app/Filament/Tenant/Resources/AssetResource.php):

   Table columns: name, type (badge), human_file_size, is_active, created_at
   Form: name, type (Select dari AssetType), file_path (TextInput — untuk sekarang manual input),
         file_url (TextInput nullable), mime_type, file_size_kb, is_active

   Note: Upload file real (ke R2/S3) baru di Phase 5. Sekarang input manual.

5. TenantSettingPage (app/Filament/Tenant/Pages/TenantSettings.php):

   Bukan Resource tapi Page (satu halaman untuk settings).
   Form fields (Schema):
     - tone (Select dari TenantTone)
     - timezone (Select: Asia/Jakarta, Asia/Makassar, Asia/Jayapura, lainnya)
     - business_hours_start (TimePicker atau TextInput format HH:MM)
     - business_hours_end
     - business_days (CheckboxList: Senin-Minggu)
     - after_hours_message (Textarea nullable)
   Save: call TenantConfigResolver::invalidateCache setelah save

6. PolicySettingPage (app/Filament/Tenant/Pages/PolicySettings.php):

   Form fields untuk semua PolicyKey:
     - PRICELIST_MODE: Select ['public', 'on_request']
     - PRICELIST_MIN_REQUIREMENT: TextInput (number, IDR)
     - LEAD_LIMIT_FALLBACK: Select ['queue', 'reject', 'notify']
     - AFTER_HOURS_BEHAVIOR: Select ['queue', 'auto_reply', 'reject']
     - INVOICE_MAX_RESEND: TextInput (number, 1-10)
     - CONCURRENT_BOOKING_LOCK: Toggle (true/false)
   Save: call TenantPolicyService::setPolicy untuk setiap key

7. Update TenantPanelProvider:
   Tambahkan semua Resources dan Pages di atas.
   Navigation groups:
     - "Pengetahuan" → PackageResource, FaqResource, KnowledgeItemResource, AssetResource
     - "Pengaturan" → TenantSettingPage, PolicySettingPage

8. Tests (app/Modules/Filament/Tests/FilamentKnowledgePanelTest.php):
   - GET /app/packages → 200 (tenant admin authenticated)
   - GET /app/faqs → 200
   - Tenant admin bisa create package → tersimpan dengan tenant_id yang benar
   - Tenant admin tidak bisa lihat package tenant lain → 404 atau 403
   - GET /app/settings → 200
   - Save TenantSetting → ter-update di DB
   - Save PolicyKey → ter-update di DB
```

---

**QA PROMPT:**
```
Verifikasi:
1. php artisan test --filter=FilamentKnowledgePanelTest → semua pass?
2. Manual test di browser (http://localhost:8080/app):
   a. Login sebagai tenant admin
   b. Navigasi ke "Pengetahuan" → "Paket"
   c. Buat paket baru dengan nama "Foto Wedding Standard"
   d. Tambahkan harga: "Weekday - Rp 8.000.000" valid dari hari ini
   e. Navigasi ke FAQ → tambah FAQ baru
   f. Navigasi ke Pengaturan → Ubah timezone ke Asia/Makassar → Save
   g. Cek DB: tenant_settings ter-update?
3. Tenant isolation: login sebagai tenant B, akses paket tenant A URL → 404?
4. PackageResource create → tenant_id otomatis = current tenant (bukan null)?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.6.
Verified: [ ] Tenant bisa input paket, harga, FAQ, pricelist, settings, policies
KANBAN-PHASE-2.md: [x] DONE
Git commit: "feat: Filament tenant panel — package, faq, knowledge, asset, settings resources"
```

---

### SUB-TASK 2.7 — WeddingDemoSeeder (Seed Data Realistis)
**Status:** [ ] TODO
**Depends On:** 2.6 selesai
**Estimated Time:** 1 jam

---

**CONTEXT PROMPT:**
```
Baca:
1. CLAUDE.md — IDENTITAS PROJECT (MVP Scope, wedding industry Jabodetabek, budget < 50 juta)
2. app/Modules/Knowledge/Models/Package.php
3. app/Modules/Knowledge/Models/PackagePrice.php
4. app/Modules/Knowledge/Models/Faq.php
5. app/Modules/Knowledge/Models/KnowledgeItem.php
6. app/Modules/TenantConfig/Models/TenantSetting.php
7. database/seeders/SuperadminSeeder.php — pola seeder yang dipakai

Depends on check:
[ ] Semua 7 tabel knowledge ada
[ ] TenantSetting model ada

Konfirmasi:
- Apa nama vendor demo yang akan dibuat?
- Mengapa seed data harus realistis dan bukan dummy?
  (hint: akan dipakai untuk accuracy test Phase 3)
```

---

**CODING PROMPT:**
```
Buat WeddingDemoSeeder yang membuat 1 tenant demo lengkap.
File: database/seeders/WeddingDemoSeeder.php

Data yang dibuat:

1. Tenant demo:
   Name: "Capture Moment Photography"
   Slug: capture-moment-photography
   Status: ACTIVE (bukan TRIAL)
   Industry: wedding
   Contact: demo@capturemoment.id

2. User tenant admin:
   Email: demo@capturemoment.id
   Password: Demo123! (di-hash)
   Name: "Capture Moment Admin"
   Role: TENANT_ADMIN, is_active: true

3. TenantSubscription: assign plan "Growth"

4. TenantSetting:
   Tone: SEMI_FORMAL
   Timezone: Asia/Jakarta
   Business hours: 09:00 - 20:00
   Business days: [1,2,3,4,5,6] (Senin-Sabtu)
   After hours message: "Halo Kak! Saat ini kami sudah tutup. Kami akan balas besok ya Kak 🙏"

5. Packages (3 paket — harga realistis wedding foto Jabodetabek):

   a. Paket Intimate:
      Slug: intimate
      Description: "Paket foto untuk pernikahan intimate dan syukuran keluarga kecil.
                    Cocok untuk acara di rumah atau gedung kecil."
      Sort order: 1

      Prices:
      - "Weekday (Senin-Jumat)": Rp 8.000.000 — valid from 2026-01-01
      - "Weekend (Sabtu-Minggu)": Rp 10.000.000 — valid from 2026-01-01

   b. Paket Standard:
      Slug: standard
      Description: "Paket lengkap untuk pernikahan akad + resepsi.
                    Termasuk 2 fotografer, prewed, dan album digital."
      Sort order: 2

      Prices:
      - "Weekday (Senin-Jumat)": Rp 15.000.000 — valid from 2026-01-01
      - "Weekend (Sabtu-Minggu)": Rp 18.000.000 — valid from 2026-01-01
      - "Peak Season (Jun-Agst)": Rp 20.000.000 — valid from 2026-06-01, valid_until 2026-08-31

   c. Paket Premium:
      Slug: premium
      Description: "Paket premium dengan coverage penuh dari persiapan hingga resepsi.
                    4 fotografer, videografi, same-day edit highlight 5 menit."
      Sort order: 3

      Prices:
      - "Weekday (Senin-Jumat)": Rp 28.000.000 — valid from 2026-01-01
      - "Weekend (Sabtu-Minggu)": Rp 32.000.000 — valid from 2026-01-01
      - "Peak Season (Jun-Agst)": Rp 38.000.000 — valid from 2026-06-01, valid_until 2026-08-31

6. FAQs (10 FAQ realistis):
   a. Q: "Berapa harga paket foto wedding Capture Moment?"
      A: "Harga kami mulai dari Rp 8.000.000 untuk paket Intimate (weekday).
          Ada 3 pilihan paket: Intimate (8-10 jt), Standard (15-18 jt), dan Premium (28-32 jt).
          Harga bisa berbeda untuk weekend dan peak season ya Kak 😊"
      Category: harga

   b. Q: "Apakah ada paket prewedding?"
      A: "Untuk saat ini prewedding sudah termasuk dalam Paket Standard dan Premium Kak.
          Untuk Paket Intimate bisa ditambahkan dengan biaya tambahan ya Kak."
      Category: paket

   c. Q: "Berapa lama foto bisa ready?"
      A: "Foto edited biasanya ready dalam 30-45 hari kerja setelah hari H Kak.
          Preview 10-20 foto akan kami kirim dalam 7 hari kerja pertama."
      Category: proses

   d. Q: "Apakah bisa request fotografer tertentu?"
      A: "Bisa Kak! Setiap paket memiliki lead fotografer yang bisa dipilih.
          Kami akan kirimkan portofolio masing-masing fotografer kami ya Kak 😊"
      Category: fotografer

   e. Q: "Bagaimana cara booking?"
      A: "Cara booking: 1) Tentukan paket dan tanggal, 2) Kirim DP 30% dari total harga,
          3) Konfirmasi dari kami dalam 1x24 jam. Tanggal dianggap fixed setelah DP masuk ya Kak."
      Category: booking

   f. Q: "Berapa DP untuk booking?"
      A: "DP booking sebesar 30% dari total paket yang dipilih Kak.
          Pembayaran DP bisa via transfer bank atau e-wallet ya Kak."
      Category: pembayaran

   g. Q: "Apakah ada extra charge untuk lokasi di luar Jabodetabek?"
      A: "Ada Kak, untuk lokasi di luar Jabodetabek dikenakan biaya transport dan akomodasi.
          Kami akan informasikan estimasinya setelah tahu lokasi lengkap ya Kak."
      Category: lokasi

   h. Q: "Apakah bisa refund jika cancel?"
      A: "Untuk pembatalan lebih dari 30 hari sebelum acara, DP bisa dikembalikan 50%.
          Untuk pembatalan kurang dari 30 hari, DP tidak dapat dikembalikan ya Kak.
          Namun bisa di-reschedule 1x tanpa biaya tambahan."
      Category: pembayaran

   i. Q: "Berapa lama durasi foto dalam 1 hari?"
      A: "Tergantung paket Kak: Intimate (6 jam), Standard (10 jam), Premium (12 jam + coverage persiapan).
          Jam tambahan bisa ditambahkan dengan biaya Rp 1.500.000/jam."
      Category: proses

   j. Q: "Format file foto yang diberikan apa saja?"
      A: "Kami deliver dalam format JPEG high-resolution dan tersimpan di Google Drive private Kak.
          Link akan aktif selamanya. Untuk format RAW bisa request dengan biaya tambahan ya Kak."
      Category: proses

7. KnowledgeItems (3 item):
   a. Title: "Syarat dan Ketentuan Layanan"
      Category: terms
      Content: [isi T&C singkat tentang booking, cancellation, delivery]
      Tags: ["terms", "booking", "cancellation"]

   b. Title: "Proses Pengerjaan & Timeline"
      Category: process
      Content: [detail timeline dari booking hingga pengiriman foto]
      Tags: ["process", "timeline", "delivery"]

   c. Title: "Area Layanan & Biaya Transport"
      Category: policy
      Content: [list area Jabodetabek gratis, luar kota ada biaya, rincian estimasi]
      Tags: ["location", "transport", "jabodetabek"]

8. Update DatabaseSeeder.php:
   Tambahkan WeddingDemoSeeder SETELAH PlanSeeder dan SuperadminSeeder.
   PENTING: WeddingDemoSeeder menggunakan updateOrCreate/firstOrCreate
   agar bisa dijalankan berulang kali (idempotent).

Setelah selesai:
docker compose exec app php artisan migrate:fresh --seed
→ verifikasi semua data terseed
```

---

**QA PROMPT:**
```
Verifikasi:
1. make fresh → tidak ada error?
2. Tinker:
   >>> App\Modules\Knowledge\Models\Package::where('slug','standard')->first()->name
   // "Paket Standard"?
   >>> App\Modules\Knowledge\Models\PackagePrice::where('label','LIKE','%Weekend%')->count()
   // 3 (satu per paket)?
   >>> App\Modules\Knowledge\Models\Faq::where('tenant_id', $demoTenantId)->count()
   // 10?
   >>> App\Modules\Knowledge\Models\Faq::first()->search_vector
   // tidak null? (trigger auto-filled)
3. PackageResolver::getActivePackages(demoTenantId) → 3 paket?
4. PriceResolver::getLowestCurrentPrice(demoTenantId) → Rp 8.000.000?
5. KnowledgeService::searchFaqs(demoTenantId, 'harga') → minimal 1 FAQ?
6. TenantConfigResolver::resolve(demoTenantId)->timezone → 'Asia/Jakarta'?
7. Login demo: curl POST /api/auth/login email=demo@capturemoment.id password=Demo123! → token?
```

---

**UPDATE PROMPT:**
```
Update PROGRESS.md Sub-task 2.7:
- Data Seeded: 3 paket, 8 harga, 10 FAQ, 3 knowledge items
- Demo Tenant: capture-moment-photography / demo@capturemoment.id / Demo123!
- Idempotent: [x] bisa dijalankan berulang

KANBAN-PHASE-2.md: [x] DONE

Git:
git add database/seeders/WeddingDemoSeeder.php database/seeders/DatabaseSeeder.php PROGRESS.md KANBAN-PHASE-2.md
git commit -m "feat: WeddingDemoSeeder — 1 tenant demo, 3 paket, 10 FAQ, data realistis"
```

---

### INTEGRATION CHECKPOINT — PHASE 2
**Status:** [ ] TODO

**PROMPT:**
```
Integration Checkpoint akhir Phase 2.

Jalankan semua verifikasi:

1. make fresh → tidak ada error
2. make test → 100% pass (berapa total test sekarang?)
3. docker compose ps → semua 10 container running

4. Knowledge Retrieval:
   [ ] PackageResolver::getActivePackages → return 3 paket demo
   [ ] PriceResolver::getActivePrice untuk tanggal peak season → return harga peak
   [ ] PriceResolver::getActivePrice untuk tanggal expired → return null
   [ ] KnowledgeService::searchFaqs('harga') → return FAQ relevan
   [ ] KnowledgeRetrieverService::retrieve → return GroundedKnowledgeDTO valid

5. tsvector Search:
   [ ] Buat FAQ baru → search_vector auto-filled via trigger
   [ ] Search "booking" → FAQ tentang booking muncul
   [ ] Search kata tidak ada → Collection kosong (tidak error)

6. Tenant Settings:
   [ ] TenantConfigResolver::resolve → TenantConfigDTO terisi lengkap
   [ ] BusinessHoursService::isOpen jam 10:00 WIB Senin → true
   [ ] BusinessHoursService::isOpen jam 22:00 WIB → false
   [ ] TenantPolicyService::getPolicy key yang belum di-set → return default

7. Tenant Isolation:
   [ ] Package tenant A tidak muncul di PackageResolver tenant B
   [ ] FAQ tenant A tidak muncul di KnowledgeService tenant B
   [ ] Filament: tenant admin tidak bisa akses data tenant lain

8. Filament Panel Tenant:
   [ ] Login demo tenant → bisa lihat 3 paket
   [ ] Bisa buat paket baru
   [ ] Bisa ubah settings tone dan timezone
   [ ] Bisa ubah policy AFTER_HOURS_BEHAVIOR

9. Full pipeline simulation:
   >>> $retriever = app(KnowledgeRetrieverInterface::class)
   >>> $result = $retriever->retrieve('ask_price', ['package_interest' => 'standard'], $demoTenantId)
   [ ] $result->structured_data['matched_package'] tidak null?
   [ ] $result->structured_data['price_range'] ada?
   [ ] $result->grounding_refs tidak kosong?
   [ ] $result->search_method == 'tsvector'?

10. Expired price test:
    [ ] Buat price dengan valid_until = yesterday → getActivePrice return null
    [ ] Harga expired tidak muncul di Filament list (jika filter active)

Exit Gate Checklist:
[ ] php artisan test → 100% PASS
[ ] docker compose up → semua running
[ ] Knowledge retrieval works (packages, prices, FAQs)
[ ] tsvector search bekerja
[ ] Tenant isolation verified semua table knowledge
[ ] TenantConfigDTO ter-populate lengkap
[ ] BusinessHoursService timezone-aware
[ ] Filament tenant panel semua resource accessible
[ ] Expired price tidak muncul
[ ] WeddingDemoSeeder idempotent (make fresh → data terseed ulang)

Jika semua PASS:
1. Update PROGRESS.md: Checkpoint DONE, Gate OPEN
2. Tulis di "CATATAN PENTING ANTAR SUB-TASK" Phase 2:
   - Knowledge schema yang dipakai
   - KnowledgeRetrieverService masih STUB (full di Phase 3)
   - Demo tenant slug dan credentials
   - Hal penting untuk Phase 3 (LLM adapter, pipeline)
3. Buat git tag:
   git tag -a v0.3-knowledge-complete -m "Knowledge & Settings complete. Tests: X pass."
4. Lanjut ke KANBAN-PHASE-3.md

Generate KANBAN-PHASE-3.md:
"Baca PROGRESS.md, CLAUDE.md, dan semua file yang sudah dibuat di Phase 1 dan Phase 2.
 Generate KANBAN-PHASE-3.md untuk AI Pipeline & Logging phase.
 Termasuk: LLM adapters, Intent/Entity classifiers, Decision Engine,
 ValidatorChain, ResponseComposer, TurnPipeline, DecisionTraceLogger.
 WAJIB: setiap service LLM punya accuracy test suite dengan MockLlmAdapter.
 Format sama persis seperti KANBAN-PHASE-1.md dan KANBAN-PHASE-2.md."
```
