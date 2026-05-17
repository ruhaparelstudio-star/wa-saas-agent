<?php

namespace App\Console\Commands;

use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\WhatsApp\Services\WaAccountService;
use Illuminate\Console\Command;

class ReconnectWaSessionsCommand extends Command
{
    protected $signature = 'wa:reconnect-sessions {--force : Reconnect all accounts, even if already connected}';
    protected $description = 'Reconnect WA accounts that are disconnected or failed';

    public function handle(WaAccountService $service): int
    {
        $statuses = [WaAccountStatus::DISCONNECTED, WaAccountStatus::FAILED, WaAccountStatus::RECONNECTING];

        if ($this->option('force')) {
            $statuses[] = WaAccountStatus::CONNECTED;
        }

        $accounts = WaAccount::withoutGlobalScopes()
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('All WA accounts are connected.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $result = $service->initiateConnect($account);
            $this->line(sprintf(
                '[%s] %s → %s',
                $account->id,
                $account->phone_number ?? 'no-phone',
                $result ? 'reconnect initiated' : 'failed'
            ));
        }

        return self::SUCCESS;
    }
}
