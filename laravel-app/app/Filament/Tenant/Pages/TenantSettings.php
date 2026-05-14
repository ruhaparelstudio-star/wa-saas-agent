<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\Shared\Enums\TenantTone;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class TenantSettings extends Page
{
    protected static ?string $navigationLabel = 'Pengaturan Bisnis';

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public function getView(): string
    {
        return 'filament.tenant.pages.tenant-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();

        $this->form->fill([
            'tone'                  => $setting?->tone?->value ?? TenantTone::SEMI_FORMAL->value,
            'timezone'              => $setting?->timezone ?? 'Asia/Jakarta',
            'business_hours_start'  => $setting?->business_hours_start ?? '08:00',
            'business_hours_end'    => $setting?->business_hours_end ?? '21:00',
            'business_days'         => $setting?->business_days ?? [1, 2, 3, 4, 5, 6],
            'after_hours_message'   => $setting?->after_hours_message,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tone')
                ->label('Gaya Bahasa')
                ->options(collect(TenantTone::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->required(),
            Select::make('timezone')
                ->label('Timezone')
                ->options([
                    'Asia/Jakarta'   => 'Asia/Jakarta (WIB, UTC+7)',
                    'Asia/Makassar'  => 'Asia/Makassar (WITA, UTC+8)',
                    'Asia/Jayapura'  => 'Asia/Jayapura (WIT, UTC+9)',
                    'Asia/Singapore' => 'Asia/Singapore (SGT, UTC+8)',
                ])
                ->required(),
            TextInput::make('business_hours_start')
                ->label('Jam Buka')
                ->required()
                ->maxLength(5)
                ->placeholder('08:00'),
            TextInput::make('business_hours_end')
                ->label('Jam Tutup')
                ->required()
                ->maxLength(5)
                ->placeholder('21:00'),
            CheckboxList::make('business_days')
                ->label('Hari Operasional')
                ->options([
                    1 => 'Senin',
                    2 => 'Selasa',
                    3 => 'Rabu',
                    4 => 'Kamis',
                    5 => 'Jumat',
                    6 => 'Sabtu',
                    7 => 'Minggu',
                ])
                ->columns(4),
            Textarea::make('after_hours_message')
                ->label('Pesan di Luar Jam Operasional')
                ->nullable()
                ->rows(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $tenantId = auth()->user()->tenant_id;

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'tone'                 => $data['tone'],
                'timezone'             => $data['timezone'],
                'business_hours_start' => $data['business_hours_start'],
                'business_hours_end'   => $data['business_hours_end'],
                'business_days'        => $data['business_days'],
                'after_hours_message'  => $data['after_hours_message'] ?? null,
            ]
        );

        app(TenantConfigResolver::class)->invalidateCache($tenantId);

        Notification::make()
            ->title('Pengaturan berhasil disimpan.')
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
