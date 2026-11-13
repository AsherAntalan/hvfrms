<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Show the user profile page.
     */
    public function index()
    {
        $user = Auth::user();
        $user->refresh()->load('role', 'branch');

        return view('profile.index', compact('user'));
    }

    public function updateInformation(Request $request): RedirectResponse
    {
        $request->merge([
            'full_name' => trim((string) $request->input('full_name')),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validateWithBag('profileInformation', [
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore(Auth::id())],
            'current_password' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.'])->errorBag('profileInformation');
            }
            if ($user->full_name === $data['full_name'] && $user->email === $data['email']) {
                return;
            }
            $changedFields = [];
            foreach (['full_name' => 'name_updated_at', 'email' => 'email_updated_at'] as $field => $timestamp) {
                if ($user->{$field} === $data[$field]) {
                    continue;
                }
                $nextUpdate = $user->{$timestamp}?->copy()->addDays(30);
                if ($nextUpdate && now()->lt($nextUpdate)) {
                    throw ValidationException::withMessages([$field => 'You can update your '.($field === 'full_name' ? 'name' : 'email').' again on '.$nextUpdate->format('M j, Y g:i A').'.'])->errorBag('profileInformation');
                }
                $user->{$field} = $data[$field];
                $user->{$timestamp} = now();
                $changedFields[] = $field;
            }
            $user->profile_updated_at = now();
            $user->save();
            AuditLog::create([
                'user_id' => $user->id, 'action' => 'profile information updated',
                'table_name' => 'users', 'record_id' => $user->id,
                'details' => 'Updated fields: '.implode(', ', $changedFields).'. Each changed field has its own 30-day waiting period.',
            ]);
        });

        return redirect()->route('profile')->with('success', 'Account information saved. Each changed field can be updated again after 30 days.');
    }

    /**
     * Update profile photo.
     */
    public function updatePhoto(Request $request)
    {
        $request->validate([
            'profile_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = Auth::user();

        // Delete old photo if exists
        if ($user->profile_photo && file_exists(public_path($user->profile_photo))) {
            @unlink(public_path($user->profile_photo));
        }

        $file = $request->file('profile_photo');
        $filename = 'profile_'.$user->id.'_'.time().'.'.$file->getClientOriginalExtension();
        $file->move(public_path('uploads/profiles'), $filename);

        $user->profile_photo = 'uploads/profiles/'.$filename;
        $user->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'profile photo updated',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);

        return redirect()->route('profile')->with('success', 'Profile photo updated successfully.');
    }

    /**
     * Change password.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = Auth::user();

        if (! Hash::check($request->current_password, $user->password_hash)) {
            return redirect()->route('profile')->with('error', 'Current password is incorrect.');
        }

        $user->password_hash = Hash::make($request->new_password);
        $user->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password changed',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);

        return redirect()->route('profile')->with('success', 'Password changed successfully.');
    }
}
