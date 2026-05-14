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
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make(PolicyKey::PRICELIST_MODE->value)
                ->label('Mode Pricelist')
                ->options([
                    'public'     => 'Public (Siapa saja bisa lihat)',
                    'on_request' => 'On Request (Hanya atas permintaan)',
                ])
                ->required(),
            TextInput::make(PolicyKey::PRICELIST_MIN_REQUIREMENT->value)
                ->label('Minimum Budget untuk Pricelist (IDR)')
                ->numeric()
                ->helperText('0 = tidak ada minimum'),
            Select::make(PolicyKey::LEAD_LIMIT_FALLBACK->value)
                ->label('Fallback saat Lead Limit Tercapai')
                ->options([
                    'queue'  => 'Queue (Antri, dibalas saat ada slot)',
                    'reject' => 'Reject (Tolak otomatis)',
                    'notify' => 'Notify (Notifikasi admin)',
                ])
                ->required(),
            Select::make(PolicyKey::AFTER_HOURS_BEHAVIOR->value)
                ->label('Perilaku di Luar Jam Operasional')
                ->options([
                    'queue'      => 'Queue (Simpan, balas saat buka)',
                    'auto_reply' => 'Auto Reply (Balas otomatis)',
                    'reject'     => 'Reject (Tolak)',
                ])
                ->required(),
            TextInput::make(PolicyKey::INVOICE_MAX_RESEND->value)
                ->label('Maksimal Kirim Ulang Invoice')
                ->numeric()
                ->minValue(1)
                ->maxValue(10),
            Toggle::make(PolicyKey::CONCURRENT_BOOKING_LOCK->value)
                ->label('Kunci Booking Bersamaan')
                ->helperText('Cegah double booking di tanggal yang sama'),
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
                ->label('Simpan')
                ->action('save'),
        ];
    }
}
