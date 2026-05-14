<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\TenantTone;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WeddingDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tenant demo
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

        // 2. User tenant admin
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

        // Link user to tenant via pivot
        TenantUser::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'id'         => Str::uuid()->toString(),
                'role'       => UserRole::TENANT_ADMIN->value,
                'is_primary' => true,
            ]
        );

        // 3. TenantSubscription: plan Growth
        $growthPlan = Plan::where('code', 'growth')->first();
        if ($growthPlan) {
            TenantSubscription::updateOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $growthPlan->id],
                [
                    'id'         => Str::uuid()->toString(),
                    'status'     => 'active',
                    'starts_at'  => Carbon::now(),
                    'ends_at'    => Carbon::now()->addYear(),
                ]
            );
        }

        // 4. TenantSetting
        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'id'                   => Str::uuid()->toString(),
                'tone'                 => TenantTone::SEMI_FORMAL,
                'timezone'             => 'Asia/Jakarta',
                'business_hours_start' => '09:00',
                'business_hours_end'   => '20:00',
                'business_days'        => [1, 2, 3, 4, 5, 6],
                'after_hours_message'  => 'Halo Kak! Saat ini kami sudah tutup. Kami akan balas besok ya Kak 🙏',
            ]
        );

        // 5. Packages
        $packages = [
            [
                'slug'        => 'intimate',
                'name'        => 'Paket Intimate',
                'description' => 'Paket foto untuk pernikahan intimate dan syukuran keluarga kecil. Cocok untuk acara di rumah atau gedung kecil.',
                'sort_order'  => 1,
                'prices'      => [
                    ['label' => 'Weekday (Senin-Jumat)', 'price_idr' => 8000000,  'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Weekend (Sabtu-Minggu)', 'price_idr' => 10000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                ],
            ],
            [
                'slug'        => 'standard',
                'name'        => 'Paket Standard',
                'description' => 'Paket lengkap untuk pernikahan akad + resepsi. Termasuk 2 fotografer, prewed, dan album digital.',
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
                'description' => 'Paket premium dengan coverage penuh dari persiapan hingga resepsi. 4 fotografer, videografi, same-day edit highlight 5 menit.',
                'sort_order'  => 3,
                'prices'      => [
                    ['label' => 'Weekday (Senin-Jumat)',  'price_idr' => 28000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Weekend (Sabtu-Minggu)', 'price_idr' => 32000000, 'valid_from' => '2026-01-01', 'valid_until' => null],
                    ['label' => 'Peak Season (Jun-Agst)', 'price_idr' => 38000000, 'valid_from' => '2026-06-01', 'valid_until' => '2026-08-31'],
                ],
            ],
        ];

        foreach ($packages as $pkgData) {
            $prices = $pkgData['prices'];
            unset($pkgData['prices']);

            $package = Package::updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $pkgData['slug']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'category' => 'wedding', 'is_active' => true], $pkgData)
            );

            foreach ($prices as $priceData) {
                PackagePrice::updateOrCreate(
                    ['package_id' => $package->id, 'label' => $priceData['label']],
                    array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'package_id' => $package->id, 'is_active' => true], $priceData)
                );
            }
        }

        // 6. FAQs
        $faqs = [
            [
                'question'   => 'Berapa harga paket foto wedding Capture Moment?',
                'answer'     => 'Harga kami mulai dari Rp 8.000.000 untuk paket Intimate (weekday). Ada 3 pilihan paket: Intimate (8-10 jt), Standard (15-18 jt), dan Premium (28-32 jt). Harga bisa berbeda untuk weekend dan peak season ya Kak 😊',
                'category'   => 'harga',
                'sort_order' => 1,
            ],
            [
                'question'   => 'Apakah ada paket prewedding?',
                'answer'     => 'Untuk saat ini prewedding sudah termasuk dalam Paket Standard dan Premium Kak. Untuk Paket Intimate bisa ditambahkan dengan biaya tambahan ya Kak.',
                'category'   => 'paket',
                'sort_order' => 2,
            ],
            [
                'question'   => 'Berapa lama foto bisa ready?',
                'answer'     => 'Foto edited biasanya ready dalam 30-45 hari kerja setelah hari H Kak. Preview 10-20 foto akan kami kirim dalam 7 hari kerja pertama.',
                'category'   => 'proses',
                'sort_order' => 3,
            ],
            [
                'question'   => 'Apakah bisa request fotografer tertentu?',
                'answer'     => 'Bisa Kak! Setiap paket memiliki lead fotografer yang bisa dipilih. Kami akan kirimkan portofolio masing-masing fotografer kami ya Kak 😊',
                'category'   => 'fotografer',
                'sort_order' => 4,
            ],
            [
                'question'   => 'Bagaimana cara booking?',
                'answer'     => 'Cara booking: 1) Tentukan paket dan tanggal, 2) Kirim DP 30% dari total harga, 3) Konfirmasi dari kami dalam 1x24 jam. Tanggal dianggap fixed setelah DP masuk ya Kak.',
                'category'   => 'booking',
                'sort_order' => 5,
            ],
            [
                'question'   => 'Berapa DP untuk booking?',
                'answer'     => 'DP booking sebesar 30% dari total paket yang dipilih Kak. Pembayaran DP bisa via transfer bank atau e-wallet ya Kak.',
                'category'   => 'pembayaran',
                'sort_order' => 6,
            ],
            [
                'question'   => 'Apakah ada extra charge untuk lokasi di luar Jabodetabek?',
                'answer'     => 'Ada Kak, untuk lokasi di luar Jabodetabek dikenakan biaya transport dan akomodasi. Kami akan informasikan estimasinya setelah tahu lokasi lengkap ya Kak.',
                'category'   => 'lokasi',
                'sort_order' => 7,
            ],
            [
                'question'   => 'Apakah bisa refund jika cancel?',
                'answer'     => 'Untuk pembatalan lebih dari 30 hari sebelum acara, DP bisa dikembalikan 50%. Untuk pembatalan kurang dari 30 hari, DP tidak dapat dikembalikan ya Kak. Namun bisa di-reschedule 1x tanpa biaya tambahan.',
                'category'   => 'pembayaran',
                'sort_order' => 8,
            ],
            [
                'question'   => 'Berapa lama durasi foto dalam 1 hari?',
                'answer'     => 'Tergantung paket Kak: Intimate (6 jam), Standard (10 jam), Premium (12 jam + coverage persiapan). Jam tambahan bisa ditambahkan dengan biaya Rp 1.500.000/jam.',
                'category'   => 'proses',
                'sort_order' => 9,
            ],
            [
                'question'   => 'Format file foto yang diberikan apa saja?',
                'answer'     => 'Kami deliver dalam format JPEG high-resolution dan tersimpan di Google Drive private Kak. Link akan aktif selamanya. Untuk format RAW bisa request dengan biaya tambahan ya Kak.',
                'category'   => 'proses',
                'sort_order' => 10,
            ],
        ];

        foreach ($faqs as $faqData) {
            Faq::updateOrCreate(
                ['tenant_id' => $tenant->id, 'question' => $faqData['question']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'is_active' => true], $faqData)
            );
        }

        // 7. KnowledgeItems
        $knowledgeItems = [
            [
                'title'    => 'Syarat dan Ketentuan Layanan',
                'category' => 'terms',
                'content'  => "Syarat dan Ketentuan Layanan Capture Moment Photography:\n\n1. BOOKING\n- Booking dianggap sah setelah DP 30% diterima dan konfirmasi tertulis dikirimkan.\n- Tanggal yang sama tidak dapat di-book oleh dua klien sekaligus.\n- Slot booking dibuka 12 bulan sebelum tanggal acara.\n\n2. PEMBATALAN & RESCHEDULE\n- Pembatalan > 30 hari sebelum acara: DP dikembalikan 50%.\n- Pembatalan ≤ 30 hari sebelum acara: DP tidak dapat dikembalikan.\n- Reschedule 1x gratis, lebih dari 1x dikenakan biaya Rp 500.000.\n\n3. PENGIRIMAN HASIL\n- Preview 10-20 foto dikirim dalam 7 hari kerja setelah acara.\n- Full album edited dikirim dalam 30-45 hari kerja.\n- File dikirim via Google Drive dengan akses private.",
                'tags'     => ['terms', 'booking', 'cancellation'],
            ],
            [
                'title'    => 'Proses Pengerjaan & Timeline',
                'category' => 'process',
                'content'  => "Timeline Layanan Capture Moment Photography:\n\nH-60 HARI (Sebelum Acara)\n- Konsultasi detail: konsep, lokasi, rundown acara\n- Penandatanganan kontrak digital\n- Pelunasan 70% sisa pembayaran\n\nH-7 HARI\n- Brief final dengan lead fotografer\n- Konfirmasi rundown dan list foto wajib\n\nHARI ACARA\n- Tim tiba 30 menit sebelum acara dimulai\n- Coverage sesuai durasi paket yang dipilih\n\nH+7 HARI\n- Preview 10-20 foto terbaik dikirim via Google Drive\n\nH+30-45 HARI\n- Full album edited (200-500 foto tergantung paket) dikirim via Google Drive\n- Revisi ringan (kecerahan, kontras) tersedia 1x gratis",
                'tags'     => ['process', 'timeline', 'delivery'],
            ],
            [
                'title'    => 'Area Layanan & Biaya Transport',
                'category' => 'policy',
                'content'  => "Area Layanan Capture Moment Photography:\n\nAREA GRATIS (Tanpa Biaya Transport)\n- Jakarta (semua wilayah)\n- Bogor Kota & Kabupaten\n- Depok\n- Tangerang Kota & Kabupaten Selatan\n- Bekasi Kota & Kabupaten\n\nLUAR JABODETABEK (Ada Biaya Tambahan)\n- Bandung: estimasi transport Rp 500.000 - Rp 1.000.000\n- Yogyakarta: estimasi Rp 1.500.000 - Rp 2.500.000 (termasuk akomodasi)\n- Bali: estimasi Rp 3.000.000 - Rp 5.000.000 (termasuk akomodasi 2 malam)\n- Kota lain: dihitung berdasarkan jarak dan kebutuhan akomodasi\n\nCATATAN: Estimasi di atas belum termasuk biaya akomodasi jika lokasi memerlukan menginap. Kami akan berikan penawaran detail setelah mengetahui lokasi lengkap.",
                'tags'     => ['location', 'transport', 'jabodetabek'],
            ],
        ];

        foreach ($knowledgeItems as $itemData) {
            KnowledgeItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'title' => $itemData['title']],
                array_merge(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'is_active' => true], $itemData)
            );
        }

        $this->command->info('WeddingDemoSeeder: Capture Moment Photography demo tenant seeded.');
        $this->command->info('  Email: demo@capturemoment.id | Password: Demo123!');
        $this->command->info('  Packages: 3 | FAQs: 10 | KnowledgeItems: 3');
    }
}
