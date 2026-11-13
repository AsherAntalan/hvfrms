<?php

namespace App\Services;

use App\Mail\AdminNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminNotifier
{
    /**
     * Email every active admin with an operational notification. Mail failures
     * are swallowed and logged so they never break the originating request.
     *
     * @param  array<int, string>  $lines
     */
    public static function send(string $subject, string $heading, array $lines): void
    {
        $recipients = User::whereHas('role', fn ($q) => $q->where('role_name', 'admin'))
            ->where('status', 'active')->whereNotNull('email')->pluck('email')->all();

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new AdminNotificationMail($heading, $lines, $subject));
            } catch (\Throwable $e) {
                Log::error('Admin notification email failed to send.', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }
    }
}
