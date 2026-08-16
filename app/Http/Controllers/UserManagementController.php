<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index()
    {
        $this->authorizeManage();

        $users = User::with(['role', 'assignedClients'])->whereNull('client_id')->latest()->get();

        $allClients = Client::where('status', 'active')->orderBy('name')->get();
        $roles = Role::whereNotIn('name', ['Client Owner'])->get();

        return view('user-management.index', compact('users', 'allClients', 'roles'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            // Client Owner sengaja dikecualikan dari dropdown (lihat index()
            // di atas) - dikunci juga di validasi biar nggak bisa dikirim
            // manual lewat request langsung.
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->where('name', '!=', 'Client Owner')),
            ],
        ]);

        $user = User::create([
            ...$validated,
            'status' => 'invited',
        ]);

        // Kegagalan kirim email (mis. SMTP belum dikonfigurasi) sengaja
        // tidak menggagalkan pembuatan user - akun tetap bisa aktif sendiri
        // saat pertama login Google, admin cukup diberi tahu lewat pesan
        // flash kalau emailnya gagal terkirim.
        $emailSent = true;
        try {
            Mail::to($user->email)->send(new UserInvitationMail($user->load('role')));
        } catch (\Throwable $e) {
            $emailSent = false;
            Log::warning('Gagal mengirim email undangan user: '.$e->getMessage(), ['user_id' => $user->id]);
        }

        $message = $emailSent
            ? 'User berhasil diundang. Email undangan sudah dikirim - mereka bisa login dengan Google menggunakan email ini.'
            : 'User berhasil dibuat, tapi email undangan gagal terkirim (cek konfigurasi SMTP di .env). Beri tahu mereka secara manual untuk login dengan Google menggunakan email ini.';

        return redirect()->route('user-management.index')->with('status', $message);
    }

    public function destroy(User $user)
    {
        $this->authorizeManage();

        // Halaman ini cuma buat kelola staf internal - binding User polos
        // tanpa scope bisa saja diarahkan ke akun client kalau ID-nya
        // ditebak/dikirim manual.
        abort_if($user->isClientUser(), 404);
        abort_if($user->id === auth()->id(), 422, 'Anda tidak bisa menonaktifkan akun sendiri.');

        $user->update(['status' => 'inactive']);

        return back()->with('status', 'User dinonaktifkan.');
    }

    public function activate(User $user)
    {
        $this->authorizeManage();

        abort_if($user->isClientUser(), 404);

        $user->update(['status' => 'active']);

        return back()->with('status', 'User berhasil diaktifkan kembali.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}