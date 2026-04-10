<?php

namespace NewSolari\Core\Module\Console;

use NewSolari\Core\Identity\Models\UserSoftBan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SoftBanExpireCommand extends Command
{
    protected $signature = 'softbans:expire';
    protected $description = 'Expire soft bans that have passed their banned_until date';

    public function handle(): int
    {
        $expired = UserSoftBan::where('deleted', false)
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
            UserSoftBan::clearBanCache($ban->user_id);
            $count++;
        }

        if ($count > 0) {
            Log::info("SoftBanExpireCommand: Expired {$count} soft ban(s)");
            $this->info("Expired {$count} soft ban(s)");
        }

        return 0;
    }
}
