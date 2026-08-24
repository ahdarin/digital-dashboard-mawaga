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
    /**
     * "Satu orang = satu record" (keputusan final user, Agustus 2026) -
     * User adalah satu-satunya entity person, tidak ada TeamMember terpisah
     * lagi. Kelola Tim query LANGSUNG ke User internal staff (client_id
     * NULL) - roster real dari GUIDE/manual, terlepas dari apakah mereka
     * punya akses login (login_enabled) atau tidak. Role dari
     * User.role_id (satu-satunya role di sistem), Client Ditangani dari
     * user_client_assignments (satu-satunya sumber, bukan dua pivot
     * terpisah lagi).
     */
    public function index(Request $request)
    {
        $this->authorizeManage();

        $users = User::whereNull('client_id')
            ->with(['role', 'assignedClients'])
            ->withCount(['currentWorkflows as active_task_count' => fn ($q) => $q->whereNotIn('current_status', WorkflowTransitions::DONE_STATUSES)])
            ->latest()->get();

        $allClients = Client::where('status', 'active')->orderBy('name')->get();
        $roles = Role::all();
        // Kandidat pengganti buat modal nonaktifkan - staff aktif lain,
        // dipopulasi selalu (bukan cuma pas ada yang nonaktifkan) biar
        // dropdown-nya nggak perlu request tambahan per baris.
        $replacementOptions = User::query()->where('status', 'active')->orderBy('name')->get();

        return view('user-management.index', compact('users', 'allClients', 'roles', 'replacementOptions'));
    }

    /**
     * "Undang User" - buat person BARU sekaligus aktifkan akses login-nya
     * (undangan = login_enabled=true, beda dari roster GUIDE yang di-import
     * tanpa akses). Role tunggal (bukan checkbox banyak role lagi).
     */
    public function store(Request $request)
    {
        $this->authorizeManage();

        // Bag terpisah ('inviteUser') - halaman ini juga punya form Edit
        // Role yang divalidasi dengan field 'role_ids' YANG SAMA PERSIS.
        // Kalau keduanya pakai bag default, validasi Edit Role yang gagal
        // akan ikut membuka modal Undang User ini juga (showCreateModal
        // di index.blade.php dulu cek $errors->any(), bukan per-form).
        $validated = $request->validateWithBag('inviteUser', [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => 'invited',
            'role_id' => $validated['role_id'],
            'login_enabled' => true,
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

    public function destroy(Request $request, User $user, PicReassignmentService $picReassignmentService)
    {
        $this->authorizeManage();

        abort_if($user->id === auth()->id(), 422, 'Anda tidak bisa menonaktifkan akun sendiri.');

        $activeCount = $picReassignmentService->countActive($user);
        $replacement = null;

        // Kalau masih ada konten aktif yang PIC-nya dia, wajib pilih
        // pengganti dulu - supaya konten itu nggak nyangkut ke user yang
        // sudah tidak bisa login sama sekali begitu dinonaktifkan.
        if ($activeCount > 0) {
            $validated = $request->validate([
                'replacement_user_id' => ['required', Rule::exists('users', 'id')->where(fn ($q) => $q->where('status', 'active')), Rule::notIn([$user->id])],
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

        $user->update(['status' => 'active']);

        return back()->with('status', 'User berhasil diaktifkan kembali.');
    }

    /**
     * "Edit Role" pada row Kelola Tim - mengedit User.role_id, SATU-SATUNYA
     * role di sistem (keputusan final user: "gabungkan sistem role dengan
     * operational role karena tetap saja sama fungsinya"). Berlaku sama
     * untuk User dengan atau tanpa login_enabled - jabatan operasional
     * TIDAK bergantung pada apakah orang itu bisa login.
     */
    public function updateRole(Request $request, User $user)
    {
        $this->authorizeManage();

        // Bag terpisah ('editRoles') - lihat catatan yang sama di store().
        $validated = $request->validateWithBag('editRoles', [
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user->update(['role_id' => $validated['role_id']]);

        return back()->with('status', "Role {$user->name} berhasil diperbarui.");
    }

    /**
     * "Assign Klien" pada row Kelola Tim - mengedit user_client_assignments
     * LANGSUNG, satu-satunya sumber "client yang ditangani" di sistem
     * (Langkah "Hapus dual concept team_member_client vs
     * user_client_assignments"). Berlaku SELALU, termasuk CEO/Manager -
     * relasi ini tetap mencatat tanggung jawab operasional real mereka,
     * terlepas dari canSeeAllClients() yang tetap jadi override akses
     * dashboard global (dua hal berbeda: siapa yang SECARA OPERASIONAL
     * menangani client apa, vs siapa yang BOLEH LIHAT semua client di
     * dashboard).
     */
    public function updateClientAssignments(Request $request, User $user)
    {
        $this->authorizeManage();

        abort_if($user->isClientUser(), 404);

        $validated = $request->validate([
            'client_ids' => 'array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $user->assignedClients()->sync($validated['client_ids'] ?? []);

        return back()->with('status', "Client yang ditangani {$user->name} berhasil diperbarui.");
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}
