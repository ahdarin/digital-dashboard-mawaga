<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index()
    {
        $this->authorizeManage();

        $users = User::with('role')->whereNull('client_id')->latest()->get();

        return view('user-management.index', compact('users'));
    }

    public function create()
    {
        $this->authorizeManage();

        $roles = Role::whereNotIn('name', ['Client Owner', 'Client Member'])->get();

        return view('user-management.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role_id' => 'required|exists:roles,id',
        ]);

        User::create([
            ...$validated,
            'status' => 'invited',
        ]);

        return redirect()->route('user-management.index')
            ->with('status', 'User berhasil diundang. Mereka bisa login dengan Google menggunakan email ini.');
    }

    public function destroy(User $user)
    {
        $this->authorizeManage();

        $user->update(['status' => 'inactive']);

        return back()->with('status', 'User dinonaktifkan.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('user_management', 'manage'), 403);
    }
}