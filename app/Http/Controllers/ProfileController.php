<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Show the user profile page.
     */
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');

        return view('profile.index', compact('user'));
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
        $filename = 'profile_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('uploads/profiles'), $filename);

        $user->profile_photo = 'uploads/profiles/' . $filename;
        $user->save();

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'profile photo updated',
            'table_name' => 'users',
            'record_id'  => $user->id,
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
            'new_password'     => 'required|min:8|confirmed',
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password_hash)) {
            return redirect()->route('profile')->with('error', 'Current password is incorrect.');
        }

        $user->password_hash = Hash::make($request->new_password);
        $user->save();

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'password changed',
            'table_name' => 'users',
            'record_id'  => $user->id,
        ]);

        return redirect()->route('profile')->with('success', 'Password changed successfully.');
    }
}
