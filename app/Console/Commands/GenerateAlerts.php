<?php

namespace App\Console\Commands;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

class GenerateAlerts extends Command
{
    protected $signature = 'alerts:generate';

    protected $description = 'Generate inventory and operational alerts appropriate to each location';

    public function handle(AlertService $service): int
    {
        $newAlerts = $service->generate();
        $this->info('Generated '.$newAlerts->count().' new alert(s).');
        $criticalIds = $newAlerts->whereIn('severity', ['critical', 'warning'])->pluck('id')->all();
        if ($criticalIds === []) {
            return self::SUCCESS;
        }
        $recipients = User::with('role')->whereHas('role', fn (Builder $roles) => $roles->whereIn('role_name', ['super_admin', 'owner', 'admin', 'commissary_manager']))
            ->where('status', 'active')->whereNotNull('email')->get();
        foreach ($recipients as $recipient) {
            $alerts = Alert::visibleTo($recipient)->whereIn('id', $criticalIds)->get();
            if ($alerts->isEmpty()) {
                continue;
            }
            try {
                Mail::to($recipient->email)->send(new AlertDigest($alerts->all()));
            } catch (\Exception $exception) {
                $this->warn('Email sending failed: '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
