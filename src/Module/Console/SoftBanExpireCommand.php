<?php

namespace NewSolari\Core\Module\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SoftBanExpireCommand extends Command
{
    protected $signature = 'softbans:expire';
    protected $description = 'Expire soft bans that have passed their banned_until date';

    public function handle(): int
    {
        // UserSoftBan model is provided by the identity package
        // This command is deprecated in core — it should be registered by the identity service
        if (!app()->bound('identity.soft_ban_model')) {
            $this->warn('Identity soft ban model not registered. Skipping.');
            return 0;
        }

        $softBanModel = app('identity.soft_ban_model');
        $expired = $softBanModel::where('deleted', false)
            ->whereNotNull('banned_until')
            ->where('banned_until', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $ban) {
            $ban->update([
                'deleted' => true,
                'deleted_by' => 'system-auto-expire',
                'deleted_at' => now(),
            ]);
            $softBanModel::clearBanCache($ban->user_id);
            $count++;
        }

        if ($count > 0) {
            Log::info("SoftBanExpireCommand: Expired {$count} soft ban(s)");
            $this->info("Expired {$count} soft ban(s)");
        }

        return 0;
    }
}
