<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\Shared\Enums\TenantTone;
use App\Modules\TenantConfig\Models\TenantBankAccount;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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

        $bankAccounts = TenantBankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ordered()
            ->get()
            ->map(fn ($acc) => [
                'id'             => $acc->id,
                'bank_name'      => $acc->bank_name,
                'account_number' => $acc->account_number,
                'account_holder' => $acc->account_holder,
                'is_default'     => (bool) $acc->is_default,
                'is_active'      => (bool) $acc->is_active,
            ])->all();

        $this->form->fill([
            'tone'                 => $setting?->tone?->value ?? TenantTone::SEMI_FORMAL->value,
            'timezone'             => $setting?->timezone ?? 'Asia/Jakarta',
            'business_hours_start' => $setting?->business_hours_start ?? '08:00',
            'business_hours_end'   => $setting?->business_hours_end ?? '21:00',
            'business_days'        => $setting?->business_days ?? [1, 2, 3, 4, 5, 6],
            'after_hours_message'  => $setting?->after_hours_message,
            'bank_accounts'        => $bankAccounts,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gaya Komunikasi AI')
                ->description('Atur bagaimana AI menyapa dan berkomunikasi dengan calon customer.')
                ->columns(2)
                ->schema([
                    Select::make('tone')
                        ->label('Gaya Bahasa')
                        ->options(collect(TenantTone::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                        ->required()
                        ->native(false)
                        ->helperText('Pilih sesuai karakter brand Anda.'),
                    Select::make('timezone')
                        ->label('Timezone')
                        ->options([
                            'Asia/Jakarta'   => 'WIB — Asia/Jakarta (UTC+7)',
                            'Asia/Makassar'  => 'WITA — Asia/Makassar (UTC+8)',
                            'Asia/Jayapura'  => 'WIT — Asia/Jayapura (UTC+9)',
                            'Asia/Singapore' => 'SGT — Asia/Singapore (UTC+8)',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Jam operasional dihitung berdasarkan timezone ini.'),
                ]),

            Section::make('Jam Operasional')
                ->description('AI hanya aktif membalas pesan dalam rentang jam ini.')
                ->columns(3)
                ->schema([
                    TimePicker::make('business_hours_start')
                        ->label('Jam Buka')
                        ->required()
                        ->seconds(false)
                        ->helperText('Contoh: 08:00'),
                    TimePicker::make('business_hours_end')
                        ->label('Jam Tutup')
                        ->required()
                        ->seconds(false)
                        ->helperText('Contoh: 21:00'),
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
                        ->columns(4)
                        ->columnSpan(3),
                ]),

            Section::make('Pesan di Luar Jam Operasional')
                ->description('Pesan ini dikirimkan otomatis ketika customer menghubungi di luar jam buka.')
                ->schema([
                    Textarea::make('after_hours_message')
                        ->label('Pesan Otomatis')
                        ->nullable()
                        ->rows(3)
                        ->maxLength(500)
                        ->placeholder('Contoh: Halo Kak! Terima kasih sudah menghubungi kami. Kami sedang offline dan akan segera balas pesan Kakak saat jam operasional (08.00–21.00). 🙏')
                        ->helperText('Kosongkan jika tidak ingin kirim pesan otomatis.'),
                ]),

            Section::make('Rekening Pembayaran')
                ->description('Rekening yang ditampilkan di pesan invoice WhatsApp dan PDF. Bisa lebih dari satu — tandai SATU sebagai default (⭐).')
                ->schema([
                    Repeater::make('bank_accounts')
                        ->label('')
                        ->schema([
                            TextInput::make('bank_name')
                                ->label('Bank / Channel')
                                ->placeholder('BCA, Mandiri, BRI, QRIS, dll')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('account_number')
                                ->label('No. Rekening / QR ID')
                                ->required()
                                ->maxLength(50),
                            TextInput::make('account_holder')
                                ->label('Atas Nama')
                                ->required()
                                ->maxLength(150),
                            Toggle::make('is_default')
                                ->label('Default')
                                ->helperText('Hanya satu yang boleh default.'),
                            Toggle::make('is_active')
                                ->label('Aktif')
                                ->default(true),
                        ])
                        ->columns(2)
                        ->addActionLabel('+ Tambah Rekening')
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string =>
                            ($state['bank_name'] ?? '') . ' — ' . ($state['account_number'] ?? '')
                            . ($state['is_default'] ?? false ? ' ⭐' : '')
                        ),
                ]),
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

        $this->syncBankAccounts($tenantId, $data['bank_accounts'] ?? []);

        app(TenantConfigResolver::class)->invalidateCache($tenantId);

        Notification::make()
            ->title('Pengaturan berhasil disimpan.')
            ->success()
            ->send();
    }

    private function syncBankAccounts(string $tenantId, array $rows): void
    {
        // Normalize: at most one default.
        $defaultSeen = false;
        foreach ($rows as &$r) {
            if (!empty($r['is_default'])) {
                $r['is_default'] = !$defaultSeen;
                $defaultSeen = $defaultSeen || (bool) $r['is_default'];
            } else {
                $r['is_default'] = false;
            }
        }
        unset($r);

        // If no default chosen but at least one row exists, mark the first one default.
        if (!$defaultSeen && !empty($rows)) {
            $rows[0]['is_default'] = true;
        }

        $existingIds = TenantBankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->all();
        $keptIds = [];

        foreach ($rows as $i => $row) {
            $payload = [
                'bank_name'      => trim((string) ($row['bank_name'] ?? '')),
                'account_number' => trim((string) ($row['account_number'] ?? '')),
                'account_holder' => trim((string) ($row['account_holder'] ?? '')),
                'is_default'     => (bool) $row['is_default'],
                'is_active'      => (bool) ($row['is_active'] ?? true),
                'sort_order'     => $i,
            ];

            if ($payload['bank_name'] === '' || $payload['account_number'] === '') {
                continue;
            }

            $id = $row['id'] ?? null;
            if ($id && in_array($id, $existingIds, true)) {
                TenantBankAccount::withoutGlobalScopes()->where('id', $id)->update($payload);
                $keptIds[] = $id;
            } else {
                $new = TenantBankAccount::create(array_merge($payload, [
                    'id'        => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                ]));
                $keptIds[] = $new->id;
            }
        }

        $toDelete = array_diff($existingIds, $keptIds);
        if (!empty($toDelete)) {
            TenantBankAccount::withoutGlobalScopes()->whereIn('id', $toDelete)->delete();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Pengaturan')
                ->icon('heroicon-o-check')
                ->action('save'),
        ];
    }
}
