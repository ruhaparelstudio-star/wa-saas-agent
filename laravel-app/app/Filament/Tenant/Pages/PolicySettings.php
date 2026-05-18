<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\TenantConfig\Support\PolicyDefaults;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PolicySettings extends Page
{
    protected static ?string $navigationLabel = 'Kebijakan';

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public function getView(): string
    {
        return 'filament.tenant.pages.policy-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $policyService = app(TenantPolicyService::class);
        $policies = $policyService->getPolicies($tenantId);

        $this->form->fill([
            PolicyKey::PRICELIST_MODE->value             => $policies[PolicyKey::PRICELIST_MODE->value] ?? PolicyDefaults::getDefault(PolicyKey::PRICELIST_MODE),
            PolicyKey::PRICELIST_MIN_REQUIREMENT->value  => $policies[PolicyKey::PRICELIST_MIN_REQUIREMENT->value] ?? PolicyDefaults::getDefault(PolicyKey::PRICELIST_MIN_REQUIREMENT),
            PolicyKey::LEAD_LIMIT_FALLBACK->value        => $policies[PolicyKey::LEAD_LIMIT_FALLBACK->value] ?? PolicyDefaults::getDefault(PolicyKey::LEAD_LIMIT_FALLBACK),
            PolicyKey::AFTER_HOURS_BEHAVIOR->value       => $policies[PolicyKey::AFTER_HOURS_BEHAVIOR->value] ?? PolicyDefaults::getDefault(PolicyKey::AFTER_HOURS_BEHAVIOR),
            PolicyKey::INVOICE_MAX_RESEND->value         => $policies[PolicyKey::INVOICE_MAX_RESEND->value] ?? PolicyDefaults::getDefault(PolicyKey::INVOICE_MAX_RESEND),
            PolicyKey::CONCURRENT_BOOKING_LOCK->value    => ($policies[PolicyKey::CONCURRENT_BOOKING_LOCK->value] ?? 'true') === 'true',
            PolicyKey::CLASSIFIER_CONTEXT_WINDOW->value  => (int) ($policies[PolicyKey::CLASSIFIER_CONTEXT_WINDOW->value] ?? PolicyDefaults::getDefault(PolicyKey::CLASSIFIER_CONTEXT_WINDOW)),
            PolicyKey::COMPOSER_CONTEXT_WINDOW->value    => (int) ($policies[PolicyKey::COMPOSER_CONTEXT_WINDOW->value] ?? PolicyDefaults::getDefault(PolicyKey::COMPOSER_CONTEXT_WINDOW)),
            PolicyKey::CONTEXT_SUMMARY_THRESHOLD->value  => (int) ($policies[PolicyKey::CONTEXT_SUMMARY_THRESHOLD->value] ?? PolicyDefaults::getDefault(PolicyKey::CONTEXT_SUMMARY_THRESHOLD)),
            PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT->value => (int) ($policies[PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT->value] ?? PolicyDefaults::getDefault(PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT)),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kebijakan Pricelist')
                ->description('Atur kapan dan kepada siapa AI mengirimkan pricelist.')
                ->columns(2)
                ->schema([
                    Select::make(PolicyKey::PRICELIST_MODE->value)
                        ->label('Mode Pengiriman Pricelist')
                        ->options([
                            'text'     => 'Teks — Daftar paket dikirim sebagai pesan chat',
                            'pdf'      => 'PDF — Lampirkan file pricelist (perlu upload PDF di Aset)',
                            'hybrid'   => 'Teks + PDF — Kirim ringkasan teks lalu file PDF',
                            'disabled' => 'Nonaktif — AI tidak pernah kirim pricelist (handoff ke sales)',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Default: Teks. Pilih PDF jika punya file pricelist resmi yang ingin dipakai.'),
                    Select::make(PolicyKey::PRICELIST_MIN_REQUIREMENT->value)
                        ->label('Syarat Minimum sebelum Pricelist Dikirim')
                        ->options([
                            'none'                  => 'Tanpa Syarat — Langsung kirim ke siapa saja yang minta',
                            'require_customer_name' => 'Wajib Nama — AI tanya nama customer dulu',
                            'after_qualification'   => 'Setelah Kualifikasi — Tunggu nama + tanggal event terkumpul',
                            'after_event_date'      => 'Setelah Tanggal Event — Tunggu customer kasih tanggal acara',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Rekomendasi: "Wajib Nama" untuk personalisasi minimum. Pakai "Setelah Kualifikasi" untuk lead yang lebih qualified.'),
                ]),

            Section::make('Kebijakan Lead & Jam Operasional')
                ->description('Atur perilaku AI saat kapasitas penuh atau di luar jam buka.')
                ->columns(2)
                ->schema([
                    Select::make(PolicyKey::LEAD_LIMIT_FALLBACK->value)
                        ->label('Jika Lead Limit Tercapai')
                        ->options([
                            'queue'  => 'Antri — Balas saat ada slot tersedia',
                            'notify' => 'Notifikasi Admin — Ping admin untuk tindak lanjut',
                            'reject' => 'Tolak — Beritahu customer kapasitas penuh',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Apa yang dilakukan AI jika kuota lead bulan ini sudah habis?'),
                    Select::make(PolicyKey::AFTER_HOURS_BEHAVIOR->value)
                        ->label('Perilaku di Luar Jam Operasional')
                        ->options([
                            'auto_reply' => 'Balas Otomatis — Kirim pesan "sedang offline"',
                            'queue'      => 'Antri — Simpan dan balas saat jam buka',
                            'reject'     => 'Tolak — Tidak merespons sama sekali',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Rekomendasi: "Balas Otomatis" agar customer tidak merasa diabaikan.'),
                ]),

            Section::make('Kebijakan Invoice & Booking')
                ->columns(2)
                ->schema([
                    TextInput::make(PolicyKey::INVOICE_MAX_RESEND->value)
                        ->label('Maks Kirim Ulang Invoice')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->suffix('kali')
                        ->helperText('Berapa kali invoice boleh dikirim ulang via WA. Rekomendasi: 3.'),
                    Toggle::make(PolicyKey::CONCURRENT_BOOKING_LOCK->value)
                        ->label('Cegah Double Booking')
                        ->helperText('Aktifkan untuk mencegah dua customer memesan tanggal yang sama secara bersamaan. Sangat direkomendasikan.'),
                ]),

            Section::make('Konteks Percakapan AI')
                ->description('Atur seberapa banyak riwayat percakapan yang dilihat AI setiap kali membalas, dan kapan ringkasan otomatis dibuat untuk percakapan panjang.')
                ->columns(2)
                ->schema([
                    TextInput::make(PolicyKey::CLASSIFIER_CONTEXT_WINDOW->value)
                        ->label('Window Pesan untuk Intent/Entity (Classifier)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->suffix('pesan')
                        ->required()
                        ->helperText('Jumlah pesan terakhir yang dilihat AI saat mengklasifikasi intent dan ekstrak entitas. Rekomendasi: 10. Lebih besar = konteks lebih kaya tapi biaya token naik.'),
                    TextInput::make(PolicyKey::COMPOSER_CONTEXT_WINDOW->value)
                        ->label('Window Pesan untuk Komposer Balasan')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('pesan')
                        ->required()
                        ->helperText('Jumlah pesan terakhir yang dilihat AI saat menyusun balasan. Rekomendasi: 20. AI pakai ini untuk hindari pengulangan & jaga gaya bahasa.'),
                    TextInput::make(PolicyKey::CONTEXT_SUMMARY_THRESHOLD->value)
                        ->label('Threshold Aktivasi Ringkasan Otomatis')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(200)
                        ->suffix('pesan')
                        ->required()
                        ->helperText('Saat total pesan dalam satu percakapan mencapai angka ini, AI otomatis membuat ringkasan pesan-pesan lama agar konteks tidak hilang. Rekomendasi: 40.'),
                    TextInput::make(PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT->value)
                        ->label('Pesan Terakhir yang Tidak Diringkas')
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(100)
                        ->suffix('pesan')
                        ->required()
                        ->helperText('Berapa pesan terakhir yang TETAP dibaca utuh (tidak diringkas). Rekomendasi: 20 (sama dengan composer window).'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $tenantId = auth()->user()->tenant_id;
        $policyService = app(TenantPolicyService::class);

        $policyService->setPolicy($tenantId, PolicyKey::PRICELIST_MODE, $data[PolicyKey::PRICELIST_MODE->value]);
        $policyService->setPolicy($tenantId, PolicyKey::PRICELIST_MIN_REQUIREMENT, (string) $data[PolicyKey::PRICELIST_MIN_REQUIREMENT->value]);
        $policyService->setPolicy($tenantId, PolicyKey::LEAD_LIMIT_FALLBACK, $data[PolicyKey::LEAD_LIMIT_FALLBACK->value]);
        $policyService->setPolicy($tenantId, PolicyKey::AFTER_HOURS_BEHAVIOR, $data[PolicyKey::AFTER_HOURS_BEHAVIOR->value]);
        $policyService->setPolicy($tenantId, PolicyKey::INVOICE_MAX_RESEND, (string) $data[PolicyKey::INVOICE_MAX_RESEND->value]);
        $policyService->setPolicy($tenantId, PolicyKey::CONCURRENT_BOOKING_LOCK, $data[PolicyKey::CONCURRENT_BOOKING_LOCK->value] ? 'true' : 'false');
        $policyService->setPolicy($tenantId, PolicyKey::CLASSIFIER_CONTEXT_WINDOW, (string) $data[PolicyKey::CLASSIFIER_CONTEXT_WINDOW->value]);
        $policyService->setPolicy($tenantId, PolicyKey::COMPOSER_CONTEXT_WINDOW, (string) $data[PolicyKey::COMPOSER_CONTEXT_WINDOW->value]);
        $policyService->setPolicy($tenantId, PolicyKey::CONTEXT_SUMMARY_THRESHOLD, (string) $data[PolicyKey::CONTEXT_SUMMARY_THRESHOLD->value]);
        $policyService->setPolicy($tenantId, PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT, (string) $data[PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT->value]);

        Notification::make()
            ->title('Kebijakan berhasil disimpan.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Kebijakan')
                ->icon('heroicon-o-check')
                ->action('save'),
        ];
    }
}
