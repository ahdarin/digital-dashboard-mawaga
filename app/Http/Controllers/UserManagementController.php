<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\PicReassignmentService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index()
    {
        $this->authorizeManage();

        $users = User::with(['roles', 'assignedClients'])
            ->withCount(['currentWorkflows as active_task_count' => fn ($q) => $q->whereNotIn('current_status', WorkflowTransitions::DONE_STATUSES)])
            ->whereNull('client_id')->latest()->get();

        $allClients = Client::where('status', 'active')->orderBy('name')->get();
        $roles = Role::whereNotIn('name', ['Client Owner'])->get();
        // Kandidat pengganti buat modal nonaktifkan - staff aktif lain,
        // dipopulasi selalu (bukan cuma pas ada yang nonaktifkan) biar
        // dropdown-nya nggak perlu request tambahan per baris.
        $replacementOptions = User::whereNull('client_id')->where('status', 'active')->orderBy('name')->get();

        return view('user-management.index', compact('users', 'allClients', 'roles', 'replacementOptions'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            // Client Owner sengaja dikecualikan dari checkbox (lihat index()
            // di atas) - dikunci juga di validasi biar nggak bisa dikirim
            // manual lewat request langsung.
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => Rule::exists('roles', 'id')->where(fn ($q) => $q->where('name', '!=', 'Client Owner')),
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => 'invited',
        ]);
        $user->roles()->sync($validated['role_ids']);

        // Kegagalan kirim email (mis. SMTP belum dikonfigurasi) sengaja
        // tidak menggagalkan pembuatan user - akun tetap bisa aktif sendiri
        // saat pertama login Google, admin cukup diberi tahu lewat pesan
        // flash kalau emailnya gagal terkirim.
        $emailSent = true;
        try {
            Mail::to($user->email)->send(new UserInvitationMail($user->load('roles')));
        } catch (\Throwable $e) {
            $emailSent = false;
            Log::warning('Gagal mengirim email undangan user: '.$e->getMessage(), ['user_id' => $user->id]);
        }

        $message = $emailSent
            ? 'User berhasil diundang. Email undangan sudah dikirim - mereka bisa login dengan Google menggunakan email ini.'
            : 'User berhasil dibuat, tapi email undangan gagal terkirim (cek konfigurasi SMTP di .env). Beri tahu mereka secara manual untuk login dengan Google menggunakan email ini.';

        return redirect()->route('user-management.index')->with('status', $message);
    }

    public function destroy(Request $request, User $user, PicReassignmentService $picReassignmentService)
    {
        $this->authorizeManage();

        // Halaman ini cuma buat kelola staf internal - binding User polos
        // tanpa scope bisa saja diarahkan ke akun client kalau ID-nya
        // ditebak/dikirim manual.
        abort_if($user->isClientUser(), 404);
        abort_if($user->id === auth()->id(), 422, 'Anda tidak bisa menonaktifkan akun sendiri.');

        $activeCount = $picReassignmentService->countActive($user);
        $replacement = null;

        // Kalau masih ada konten aktif yang PIC-nya dia, wajib pilih
        // pengganti dulu - supaya konten itu nggak nyangkut ke user yang
        // sudah tidak bisa login sama sekali begitu dinonaktifkan.
        if ($activeCount > 0) {
            $validated = $request->validate([
                'replacement_user_id' => ['required', Rule::exists('users', 'id')->where(fn ($q) => $q->where('status', 'active')->whereNull('client_id')), Rule::notIn([$user->id])],
            ]);

            $replacement = User::findOrFail($validated['replacement_user_id']);
            $picReassignmentService->transferAll($user, $replacement);
        }

        $user->update(['status' => 'inactive']);

        $message = $activeCount > 0
            ? "User dinonaktifkan. {$activeCount} tugas aktif dipindahkan ke {$replacement->name}."
            : 'User dinonaktifkan.';

        return back()->with('status', $message);
    }

    public function activate(User $user)
    {
        $this->authorizeManage();

        abort_if($user->isClientUser(), 404);

        $user->update(['status' => 'active']);

        return back()->with('status', 'User berhasil diaktifkan kembali.');
    }

    public function updateRoles(Request $request, User $user)
    {
        $this->authorizeManage();

        abort_if($user->isClientUser(), 404);

        $validated = $request->validate([
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => Rule::exists('roles', 'id')->where(fn ($q) => $q->where('name', '!=', 'Client Owner')),
        ]);

        $user->roles()->sync($validated['role_ids']);

        return redirect()->route('user-management.index')
            ->with('status', "Role untuk {$user->name} berhasil diperbarui.");
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}