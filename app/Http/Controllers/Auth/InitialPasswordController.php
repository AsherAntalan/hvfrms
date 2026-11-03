<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\RememberToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class InitialPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect('/');
        }

        return response()->view('auth.initial-password')->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $user->must_change_password || $user->status !== 'active' || ! $user->temporary_password_expires_at?->isFuture()
                || ! Hash::check($data['current_password'], $user->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'Temporary password is invalid or expired. Contact your manager for replacement credentials.']);
            }
            $user->update(['password_hash' => Hash::make($data['password']), 'must_change_password' => false, 'temporary_password_expires_at' => null]);
            AccountRequest::where('user_id', $user->id)->update(['temporary_password' => null]);
            RememberToken::where('user_id', $user->id)->delete();
            AuditLog::create(['user_id' => $user->id, 'action' => 'initial_password_changed', 'table_name' => 'users', 'record_id' => $user->id]);
        }, 3);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Password set. Sign in with your new password.');
    }
}
