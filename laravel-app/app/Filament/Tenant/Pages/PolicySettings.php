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
            PolicyKey::PRICELIST_MODE->value            => $policies[PolicyKey::PRICELIST_MODE->value] ?? PolicyDefaults::getDefault(PolicyKey::PRICELIST_MODE),
            PolicyKey::PRICELIST_MIN_REQUIREMENT->value => $policies[PolicyKey::PRICELIST_MIN_REQUIREMENT->value] ?? PolicyDefaults::getDefault(PolicyKey::PRICELIST_MIN_REQUIREMENT),
            PolicyKey::LEAD_LIMIT_FALLBACK->value       => $policies[PolicyKey::LEAD_LIMIT_FALLBACK->value] ?? PolicyDefaults::getDefault(PolicyKey::LEAD_LIMIT_FALLBACK),
            PolicyKey::AFTER_HOURS_BEHAVIOR->value      => $policies[PolicyKey::AFTER_HOURS_BEHAVIOR->value] ?? PolicyDefaults::getDefault(PolicyKey::AFTER_HOURS_BEHAVIOR),
            PolicyKey::INVOICE_MAX_RESEND->value        => $policies[PolicyKey::INVOICE_MAX_RESEND->value] ?? PolicyDefaults::getDefault(PolicyKey::INVOICE_MAX_RESEND),
            PolicyKey::CONCURRENT_BOOKING_LOCK->value   => ($policies[PolicyKey::CONCURRENT_BOOKING_LOCK->value] ?? 'true') === 'true',
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
                        ->label('Mode Pricelist')
                        ->options([
                            'public'     => 'Publik — Siapa saja bisa minta pricelist',
                            'on_request' => 'Atas Permintaan — AI bertanya dulu sebelum kirim',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Rekomendasi: "Atas Permintaan" untuk lead qualification lebih baik.'),
                    TextInput::make(PolicyKey::PRICELIST_MIN_REQUIREMENT->value)
                        ->label('Minimal Budget untuk Lihat Pricelist (IDR)')
                        ->numeric()
                        ->prefix('Rp')
                        ->helperText('Isi 0 jika tidak ada batasan minimum budget.'),
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
