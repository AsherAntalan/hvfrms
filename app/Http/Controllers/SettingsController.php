<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class SettingsController extends Controller
{
    /**
     * Show the settings page.
     */
    public function index()
    {
        $all = Setting::orderBy('setting_key')->get();
        $cfg = [];
        foreach ($all as $s) {
            $cfg[$s->setting_key] = [
                'value' => $s->setting_value,
                'desc' => $s->description,
            ];
        }

        return view('settings.index', compact('cfg'));
    }

    /**
     * Update settings (POST).
     */
    public function update(Request $request)
    {
        $fields = [
            'app_name' => 'Application Name',
            'forecast_weeks' => 'Forecast Weeks',
            'safety_stock_factor' => 'Safety Stock Factor',
            'unusual_sales_sigma' => 'Unusual Sales Sigma',
            'idle_branch_days' => 'Idle Branch Days',
            'expiry_warning_days' => 'Expiry Warning Days',
            'order_max_qty_per_line' => 'Order Max Qty Per Line',
        ];

        foreach ($fields as $key => $label) {
            if ($request->has($key)) {
                $val = trim($request->input($key));
                if ($key === 'order_max_qty_per_line') {
                    // Whole number ≥ 0 (0 = unlimited). Reject malformed input.
                    if (! ctype_digit($val)) {
                        return redirect()->route('settings')->with('error', 'Order max qty per line must be a whole number (0 = unlimited).');
                    }
                    Setting::set($key, (string) (int) $val);
                } elseif ($val !== '') {
                    Setting::set($key, $val);
                }
            }
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'settings updated',
            'table_name' => 'settings',
            'record_id' => 0,
            'details' => 'System settings updated.',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('settings')->with('success', 'Settings saved successfully.');
    }

    /**
     * Trigger a database backup (super_admin/owner only).
     */
    public function backup()
    {
        Artisan::call('db:backup');
        $output = Artisan::output();

        if (str_contains($output, 'successfully')) {
            return redirect()->route('settings')->with('success', trim($output));
        }

        return redirect()->route('settings')->with('error', trim($output));
    }
}
