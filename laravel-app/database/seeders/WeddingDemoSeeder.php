<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Lead\Models\Lead;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\TenantTone;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WeddingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $proPlan = Plan::where('code', 'pro')->first();

        $this->seedPhotographyTenant($proPlan);
        $this->seedCateringTenant($proPlan);
    }

    private function seedPhotographyTenant(?Plan $proPlan): void
    {
        // 1. Tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'capture-moment-photography'],
            [
                'id'            => Str::uuid()->toString(),
                'name'          => 'Capture Moment Photography',
                'status'        => TenantStatus::ACTIVE,
                'industry'      => 'wedding',
                'contact_email' => 'demo@capturemoment.id',
            ]
        );

        // 2. User
        $user = User::updateOrCreate(
            ['email' => 'demo@capturemoment.id'],
            [
                'id'        => Str::uuid()->toString(),
                'name'      => 'Capture Moment Admin',
                'password'  => Hash::make('Demo123!'),
                'role'      => UserRole::TENANT_ADMIN,
                'is_active' => true,
                'tenant_id' => $tenant->id,
            ]
        );

        TenantUser::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'id'         => Str::uuid()->toString(),
                'role'       => UserRole::TENANT_ADMIN->value,
                'is_primary' => true,
            ]
        );

        // 3. Subscription: Pro plan (ANALYTICS_ADVANCED=true)
        if ($proPlan) {
            TenantSubscription::updateOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $proPlan->id],
                [
                    'id'                   => Str::uuid()->toString(),
                    'status'               => 'active',
                    'starts_at'            => Carbon::now()->subMonth(),
                    'ends_at'              => Carbon::now()->addYear(),
                    'current_period_start' => Carbon::now()->subMonth(),
                    'current_period_end'   => Carbon::now(),
                ]
            );
        }

        // 4. TenantSetting
        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'id'                        => Str::uuid()->toString(),
                'tone'                      => TenantTone::SEMI_FORMAL,
                'timezone'                  => 'Asia/Jakarta',
                'business_hours_start'      => '09:00',
                'business_hours_end'        => '20:00',
                'business_days'             => [1, 2, 3, 4, 5, 6],
                'after_hours_message'       => 'Halo Kak! Saat ini kami sudah tutup. Kami akan balas besok ya Kak 🙏',
                'google_calendar_enabled'   => false,
            ]
        );

        // 5. Packages
        $packages = $this->photographyPackages();
        $pkgModels = [];
        foreach ($packages as $pkgData) {
            $prices = $pkgData['prices'];
            unset($pkgData['prices']);

            $pkg = Package::updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $pkgData['slug']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'category' => 'wedding', 'is_active' => true], $pkgData)
            );

            foreach ($prices as $priceData) {
                PackagePrice::updateOrCreate(
                    ['package_id' => $pkg->id, 'label' => $priceData['label']],
                    array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'package_id' => $pkg->id, 'is_active' => true], $priceData)
                );
            }

            $pkgModels[$pkgData['slug']] = $pkg;
        }

        // 6. FAQs
        foreach ($this->photographyFaqs() as $faqData) {
            Faq::updateOrCreate(
                ['tenant_id' => $tenant->id, 'question' => $faqData['question']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'is_active' => true], $faqData)
            );
        }

        // 7. KnowledgeItems
        foreach ($this->photographyKnowledge() as $itemData) {
            KnowledgeItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'title' => $itemData['title']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'is_active' => true], $itemData)
            );
        }

        // 8. WA Account — CONNECTED
        WaAccount::updateOrCreate(
            ['tenant_id' => $tenant->id, 'display_name' => 'CS Utama'],
            [
                'id'        => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'status'    => WaAccountStatus::CONNECTED,
            ]
        );

        // 9. Conversations (10 per tenant — beragam stage)
        $this->seedConversationsAndBookings($tenant, $pkgModels);

        $this->command->info('WeddingDemoSeeder: Capture Moment Photography seeded.');
        $this->command->info('  Email: demo@capturemoment.id | Password: Demo123!');
    }

    private function seedCateringTenant(?Plan $proPlan): void
    {
        // 1. Tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'sari-katering-nusantara'],
            [
                'id'            => Str::uuid()->toString(),
                'name'          => 'Sari Katering Nusantara',
                'status'        => TenantStatus::ACTIVE,
                'industry'      => 'wedding',
                'contact_email' => 'demo@sarikatering.id',
            ]
        );

        // 2. User
        $user = User::updateOrCreate(
            ['email' => 'demo@sarikatering.id'],
            [
                'id'        => Str::uuid()->toString(),
                'name'      => 'Sari Katering Admin',
                'password'  => Hash::make('Demo123!'),
                'role'      => UserRole::TENANT_ADMIN,
                'is_active' => true,
                'tenant_id' => $tenant->id,
            ]
        );

        TenantUser::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'id'         => Str::uuid()->toString(),
                'role'       => UserRole::TENANT_ADMIN->value,
                'is_primary' => true,
            ]
        );

        // 3. Subscription: Pro plan
        if ($proPlan) {
            TenantSubscription::updateOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $proPlan->id],
                [
                    'id'                   => Str::uuid()->toString(),
                    'status'               => 'active',
                    'starts_at'            => Carbon::now()->subMonth(),
                    'ends_at'              => Carbon::now()->addYear(),
                    'current_period_start' => Carbon::now()->subMonth(),
                    'current_period_end'   => Carbon::now(),
                ]
            );
        }

        // 4. TenantSetting
        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'id'                        => Str::uuid()->toString(),
                'tone'                      => TenantTone::FRIENDLY,
                'timezone'                  => 'Asia/Jakarta',
                'business_hours_start'      => '08:00',
                'business_hours_end'        => '21:00',
                'business_days'             => [1, 2, 3, 4, 5, 6, 7],
                'after_hours_message'       => 'Halo! Kami sudah offline. Akan segera kami balas ya 😊',
                'google_calendar_enabled'   => false,
            ]
        );

        // 5. Packages (Katering)
        $packages = $this->cateringPackages();
        $pkgModels = [];
        foreach ($packages as $pkgData) {
            $prices = $pkgData['prices'];
            unset($pkgData['prices']);

            $pkg = Package::updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $pkgData['slug']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'category' => 'wedding', 'is_active' => true], $pkgData)
            );

            foreach ($prices as $priceData) {
                PackagePrice::updateOrCreate(
                    ['package_id' => $pkg->id, 'label' => $priceData['label']],
                    array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'package_id' => $pkg->id, 'is_active' => true], $priceData)
                );
            }

            $pkgModels[$pkgData['slug']] = $pkg;
        }

        // 6. FAQs
        foreach ($this->cateringFaqs() as $faqData) {
            Faq::updateOrCreate(
                ['tenant_id' => $tenant->id, 'question' => $faqData['question']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'is_active' => true], $faqData)
            );
        }

        // 7. KnowledgeItems
        KnowledgeItem::updateOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Menu & Paket Katering Lengkap'],
            [
                'id'        => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'category'  => 'menu',
                'content'   => "Sari Katering Nusantara menyediakan berbagai pilihan menu:\n\nPAKET SILVER (50-200 pax): Nasi putih + 5 lauk pilihan + minuman\nPAKET GOLD (100-500 pax): Nasi putih + 8 lauk + 2 dessert + minuman\nPAKET PLATINUM (200-1000 pax): Nasi putih + 12 lauk + 3 dessert + live station + minuman\n\nSemua paket termasuk setup peralatan makan, pramusaji, dan bersih-bersih setelah acara.",
                'tags'      => ['menu', 'paket', 'katering'],
                'is_active' => true,
            ]
        );

        // 8. WA Account — CONNECTED
        WaAccount::updateOrCreate(
            ['tenant_id' => $tenant->id, 'display_name' => 'CS Katering'],
            [
                'id'        => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'status'    => WaAccountStatus::CONNECTED,
            ]
        );

        // 9. Conversations & Bookings
        $this->seedConversationsAndBookings($tenant, $pkgModels);

        $this->command->info('WeddingDemoSeeder: Sari Katering Nusantara seeded.');
        $this->command->info('  Email: demo@sarikatering.id | Password: Demo123!');
    }

    private function seedConversationsAndBookings(Tenant $tenant, array $pkgModels): void
    {
        $waAccount = WaAccount::where('tenant_id', $tenant->id)->first();

        // Conversations distribution sesuai KANBAN 7.7:
        // 3 NEW_LEAD (cold), 2 QUALIFICATION (warm), 2 RECOMMENDATION (warm),
        // 1 BOOKING (hot), 1 INVOICE_PHASE (hot), 1 CLOSED (hot)

        // Event dates must be distinct per tenant to satisfy the unique constraint on (tenant_id, event_date, event_type).
        // Use a tenant-specific offset (first 8 chars of tenant ID turned into an integer) so two tenants
        // running through the same loop get different absolute dates.
        $tenantOffset = (int) (hexdec(substr(str_replace('-', '', $tenant->id), 0, 6)) % 60);

        $conversationDefs = [
            ['stage' => ConversationStage::NEW_LEAD,      'temp' => LeadTemperature::COLD,  'phone' => '+6281100000001', 'name' => 'Dewi Rahayu',    'days_ago' => 5],
            ['stage' => ConversationStage::NEW_LEAD,      'temp' => LeadTemperature::COLD,  'phone' => '+6281100000002', 'name' => 'Rizki Pratama',  'days_ago' => 3],
            ['stage' => ConversationStage::NEW_LEAD,      'temp' => LeadTemperature::COLD,  'phone' => '+6281100000003', 'name' => 'Laras Setiawati','days_ago' => 1],
            ['stage' => ConversationStage::QUALIFICATION, 'temp' => LeadTemperature::WARM,  'phone' => '+6281100000004', 'name' => 'Budi Santoso',   'days_ago' => 7],
            ['stage' => ConversationStage::QUALIFICATION, 'temp' => LeadTemperature::WARM,  'phone' => '+6281100000005', 'name' => 'Sinta Marlina',  'days_ago' => 6],
            ['stage' => ConversationStage::RECOMMENDATION,'temp' => LeadTemperature::WARM,  'phone' => '+6281100000006', 'name' => 'Andi Kusuma',    'days_ago' => 10],
            ['stage' => ConversationStage::RECOMMENDATION,'temp' => LeadTemperature::WARM,  'phone' => '+6281100000007', 'name' => 'Maya Pertiwi',   'days_ago' => 9],
            // Bookings use distinct event_months (2, 4, 6 months from now) + tenant-specific day offset.
            ['stage' => ConversationStage::BOOKING,       'temp' => LeadTemperature::HOT,   'phone' => '+6281100000008', 'name' => 'Hendra Gunawan', 'days_ago' => 14, 'has_booking' => true, 'booking_status' => BookingStatus::CONFIRMED, 'event_months_ahead' => 2],
            ['stage' => ConversationStage::INVOICE_PHASE, 'temp' => LeadTemperature::HOT,   'phone' => '+6281100000009', 'name' => 'Fitri Ananda',   'days_ago' => 20, 'has_booking' => true, 'booking_status' => BookingStatus::PAID, 'has_invoice' => true, 'event_months_ahead' => 4],
            ['stage' => ConversationStage::CLOSED,        'temp' => LeadTemperature::HOT,   'phone' => '+6281100000010', 'name' => 'Teguh Wibowo',   'days_ago' => 30, 'has_booking' => true, 'booking_status' => BookingStatus::COMPLETED, 'event_months_ahead' => 6],
        ];

        $packageSlugs = array_keys($pkgModels);

        foreach ($conversationDefs as $i => $def) {
            $phone = $def['phone'] . substr($tenant->id, 0, 4);
            $createdAt = Carbon::now()->subDays($def['days_ago']);

            $conv = Conversation::updateOrCreate(
                ['tenant_id' => $tenant->id, 'customer_phone' => $phone],
                [
                    'id'               => Str::uuid()->toString(),
                    'tenant_id'        => $tenant->id,
                    'wa_account_id'    => $waAccount?->id,
                    'channel'          => 'whatsapp',
                    'customer_phone'   => $phone,
                    'customer_name'    => $def['name'],
                    'stage'            => $def['stage'],
                    'agent_mode'       => AgentMode::ACTIVE,
                    'memory_mode'      => MemoryMode::ACTIVE,
                    'lead_temperature' => $def['temp'],
                    'message_count'    => rand(3, 15),
                    'last_message_at'  => $createdAt->copy()->addHours(rand(1, 5)),
                    'entity_cache'     => [
                        'customer_name' => $def['name'],
                        'event_type'    => 'resepsi',
                        'guest_count'   => rand(100, 500),
                        'budget_max'    => rand(5, 30) * 1000000,
                    ],
                    'created_at'       => $createdAt,
                    'updated_at'       => $createdAt->copy()->addHours(2),
                ]
            );

            // Lead profile
            Lead::updateOrCreate(
                ['tenant_id' => $tenant->id, 'conversation_id' => $conv->id],
                [
                    'id'              => Str::uuid()->toString(),
                    'tenant_id'       => $tenant->id,
                    'conversation_id' => $conv->id,
                    'customer_name'   => $def['name'],
                    'customer_phone'  => $phone,
                    'event_date'      => Carbon::now()->addMonths(rand(2, 8))->toDateString(),
                    'event_type'      => 'resepsi',
                    'location'        => 'Jakarta Selatan',
                    'guest_count'     => rand(100, 500),
                    'budget_min'      => 5000000,
                    'budget_max'      => rand(10, 40) * 1000000,
                    'package_interest'=> $packageSlugs[0] ?? null,
                    'package_slug'    => $packageSlugs[0] ?? null,
                    'temperature'     => $def['temp'],
                    'lead_score'      => match($def['temp']) {
                        LeadTemperature::HOT  => rand(80, 100),
                        LeadTemperature::WARM => rand(40, 79),
                        LeadTemperature::COLD => rand(0, 39),
                    },
                ]
            );

            if (!empty($def['has_booking'])) {
                $pkgSlug  = $packageSlugs[array_key_first($pkgModels)] ?? null;
                $pkg      = $pkgSlug ? $pkgModels[$pkgSlug] : null;
                $total    = ($i + 2) * 5000000; // deterministic, not random
                $dp       = (int) ($total * 0.3);
                // Use a fixed month ahead + tenant-specific day offset to guarantee uniqueness
                // within the same tenant across the 3 bookings, and across tenants.
                $eventDate = Carbon::now()
                    ->addMonths($def['event_months_ahead'])
                    ->addDays($tenantOffset)
                    ->startOfDay();

                $booking = Booking::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'conversation_id' => $conv->id],
                    [
                        'id'              => Str::uuid()->toString(),
                        'tenant_id'       => $tenant->id,
                        'conversation_id' => $conv->id,
                        'package_id'      => $pkg?->id,
                        'booking_code'    => DB::transaction(fn () => Booking::generateBookingCode($tenant->id)),
                        'status'          => $def['booking_status'],
                        'event_date'      => $eventDate->toDateString(),
                        'event_time_start'=> '09:00',
                        'event_time_end'  => '17:00',
                        'event_type'      => 'resepsi',
                        'location'        => 'Jakarta Selatan',
                        'guest_count'     => rand(150, 400),
                        'customer_name'   => $def['name'],
                        'customer_phone'  => $phone,
                        'total_amount'    => $total,
                        'dp_amount'       => $dp,
                        'confirmed_at'    => Carbon::now()->subDays($def['days_ago'] - 2),
                    ]
                );

                if (!empty($def['has_invoice'])) {
                    // DP invoice — PAID
                    Invoice::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'type' => InvoiceType::DP],
                        [
                            'id'             => Str::uuid()->toString(),
                            'tenant_id'      => $tenant->id,
                            'booking_id'     => $booking->id,
                            'invoice_number' => DB::transaction(fn () => Invoice::generateInvoiceNumber()),
                            'type'           => InvoiceType::DP,
                            'status'         => InvoiceStatus::PAID,
                            'amount'         => $dp,
                            'due_date'       => Carbon::now()->subDays(5)->toDateString(),
                            'sent_count'     => 1,
                            'sent_at'        => Carbon::now()->subDays(8),
                            'paid_at'        => Carbon::now()->subDays(5),
                        ]
                    );

                    // Pelunasan invoice — ISSUED (belum dibayar)
                    Invoice::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'type' => InvoiceType::PELUNASAN],
                        [
                            'id'             => Str::uuid()->toString(),
                            'tenant_id'      => $tenant->id,
                            'booking_id'     => $booking->id,
                            'invoice_number' => DB::transaction(fn () => Invoice::generateInvoiceNumber()),
                            'type'           => InvoiceType::PELUNASAN,
                            'status'         => InvoiceStatus::ISSUED,
                            'amount'         => $total - $dp,
                            'due_date'       => Carbon::now()->addDays(30)->toDateString(),
                            'sent_count'     => 0,
                        ]
                    );
                }
            }
        }
    }

    // ── Photography data ────────────────────────────────────────────────────

    private function photographyPackages(): array
    {
        return [
            [
                'slug'        => 'intimate',
                'name'        => 'Paket Intimate',
                'description' => 'Paket foto untuk pernikahan intimate dan syukuran keluarga kecil.',
                'sort_order'  => 1,
                'prices'      => [
                    ['label' => 'Weekday (Senin-Jumat)',  'price_idr' => 8000000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Weekend (Sabtu-Minggu)', 'price_idr' => 10000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                ],
            ],
            [
                'slug'        => 'standard',
                'name'        => 'Paket Standard',
                'description' => 'Paket lengkap untuk pernikahan akad + resepsi. Termasuk 2 fotografer.',
                'sort_order'  => 2,
                'prices'      => [
                    ['label' => 'Weekday (Senin-Jumat)',  'price_idr' => 15000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Weekend (Sabtu-Minggu)', 'price_idr' => 18000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Peak Season (Jun-Agst)', 'price_idr' => 20000000, 'valid_from' => '2026-06-01', 'valid_until' => '2026-08-31'],
                ],
            ],
            [
                'slug'        => 'premium',
                'name'        => 'Paket Premium',
                'description' => 'Coverage penuh dari persiapan hingga resepsi. 4 fotografer + videografi.',
                'sort_order'  => 3,
                'prices'      => [
                    ['label' => 'Weekday (Senin-Jumat)',  'price_idr' => 28000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Weekend (Sabtu-Minggu)', 'price_idr' => 32000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Peak Season (Jun-Agst)', 'price_idr' => 38000000, 'valid_from' => '2026-06-01', 'valid_until' => '2026-08-31'],
                ],
            ],
        ];
    }

    private function photographyFaqs(): array
    {
        return [
            ['question' => 'Berapa harga paket foto wedding Capture Moment?', 'answer' => 'Harga kami mulai dari Rp 8.000.000 untuk paket Intimate (weekday). Ada 3 pilihan paket ya Kak 😊', 'category' => 'harga', 'sort_order' => 1],
            ['question' => 'Apakah ada paket prewedding?', 'answer' => 'Prewedding termasuk dalam Paket Standard dan Premium Kak.', 'category' => 'paket', 'sort_order' => 2],
            ['question' => 'Berapa lama foto bisa ready?', 'answer' => 'Foto edited ready dalam 30-45 hari kerja setelah hari H Kak.', 'category' => 'proses', 'sort_order' => 3],
            ['question' => 'Bagaimana cara booking?', 'answer' => 'Tentukan paket dan tanggal, kirim DP 30%, konfirmasi dalam 1x24 jam ya Kak.', 'category' => 'booking', 'sort_order' => 4],
            ['question' => 'Berapa DP untuk booking?', 'answer' => 'DP sebesar 30% dari total paket yang dipilih Kak.', 'category' => 'pembayaran', 'sort_order' => 5],
            ['question' => 'Apakah bisa refund jika cancel?', 'answer' => 'Pembatalan > 30 hari: DP dikembalikan 50%. Kurang dari 30 hari: DP tidak dapat dikembalikan ya Kak.', 'category' => 'pembayaran', 'sort_order' => 6],
        ];
    }

    private function photographyKnowledge(): array
    {
        return [
            [
                'title'    => 'Syarat dan Ketentuan Layanan',
                'category' => 'terms',
                'content'  => "Booking sah setelah DP 30% diterima.\nPembatalan > 30 hari: DP kembali 50%.\nReschedule 1x gratis.",
                'tags'     => ['terms', 'booking', 'cancellation'],
            ],
            [
                'title'    => 'Area Layanan & Biaya Transport',
                'category' => 'policy',
                'content'  => "Area gratis: Jakarta, Bogor, Depok, Tangerang, Bekasi.\nLuar Jabodetabek: ada biaya transport.",
                'tags'     => ['location', 'transport'],
            ],
        ];
    }

    // ── Catering data ───────────────────────────────────────────────────────

    private function cateringPackages(): array
    {
        return [
            [
                'slug'        => 'silver',
                'name'        => 'Paket Silver',
                'description' => 'Paket katering untuk 50-200 pax dengan 5 lauk pilihan.',
                'sort_order'  => 1,
                'prices'      => [
                    ['label' => 'Per Pax (min 50 pax)',   'price_idr' => 75000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Per Pax (min 100 pax)',  'price_idr' => 65000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                ],
            ],
            [
                'slug'        => 'gold',
                'name'        => 'Paket Gold',
                'description' => 'Paket katering untuk 100-500 pax dengan 8 lauk + 2 dessert.',
                'sort_order'  => 2,
                'prices'      => [
                    ['label' => 'Per Pax (min 100 pax)',  'price_idr' => 95000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Per Pax (min 300 pax)',  'price_idr' => 85000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                ],
            ],
            [
                'slug'        => 'platinum',
                'name'        => 'Paket Platinum',
                'description' => 'Paket full service 200-1000 pax dengan live station + 12 lauk.',
                'sort_order'  => 3,
                'prices'      => [
                    ['label' => 'Per Pax (min 200 pax)',  'price_idr' => 125000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Per Pax (min 500 pax)',  'price_idr' => 110000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                ],
            ],
        ];
    }

    private function cateringFaqs(): array
    {
        return [
            ['question' => 'Berapa harga katering per pax?', 'answer' => 'Harga mulai dari Rp 65.000/pax untuk Paket Silver (min 100 pax) ya Kak 😊', 'category' => 'harga', 'sort_order' => 1],
            ['question' => 'Apakah termasuk alat makan?', 'answer' => 'Ya Kak, semua paket sudah termasuk alat makan, pramusaji, dan bersih-bersih.', 'category' => 'fasilitas', 'sort_order' => 2],
            ['question' => 'Berapa minimum order?', 'answer' => 'Minimum 50 pax untuk Paket Silver, 100 pax untuk Gold, dan 200 pax untuk Platinum ya Kak.', 'category' => 'order', 'sort_order' => 3],
            ['question' => 'Apakah bisa request menu custom?', 'answer' => 'Bisa Kak! Kami bisa sesuaikan menu dengan selera dan kebutuhan acara. Ada biaya tambahan untuk menu khusus ya Kak.', 'category' => 'menu', 'sort_order' => 4],
        ];
    }
}
