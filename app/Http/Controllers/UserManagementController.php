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
     * lagi. User HANYA internal staff (Client bukan User sama sekali, lihat
     * Client::portal_token), jadi Kelola Tim query semua User tanpa filter -
     * roster real dari GUIDE/manual, terlepas dari apakah mereka punya akses
     * login (login_enabled) atau tidak. Role dari user_roles (many-to-many,
     * satu user bisa punya beberapa role), Client Ditangani dari
     * user_client_assignments (satu-satunya sumber, bukan dua pivot
     * terpisah lagi).
     */
    public function index(Request $request)
    {
        // Halaman ini sekarang juga dibuka untuk role view-only (mis.
        // Admin) - route-nya sendiri sudah digerbang 'user_management,view'
        // (lihat routes/web.php), jadi authorizeManage() (yang mensyaratkan
        // 'manage') di sini sengaja TIDAK dipakai buat index(). Semua aksi
        // tulis (store/destroy/activate/dst) tetap lewat authorizeManage().
        abort_unless(
            auth()->user()?->hasPermissionTo('user_management', 'view')
                || auth()->user()?->hasPermissionTo('user_management', 'manage'),
            403
        );

        // Tab "Aktif"/"Nonaktif" (pola sama seperti Produksi) - dipisah
        // supaya roster nonaktif yang menumpuk tidak membanjiri tabel utama.
        $tab = $request->input('tab') === 'nonaktif' ? 'nonaktif' : 'aktif';

        $users = User::query()
            ->with(['roles', 'assignedClients'])
            ->withCount(['currentWorkflows as active_task_count' => fn ($q) => $q->whereNotIn('current_status', WorkflowTransitions::DONE_STATUSES)])
            ->when(
                $tab === 'nonaktif',
                fn ($q) => $q->where('status', 'inactive'),
                fn ($q) => $q->where('status', '!=', 'inactive'),
            )
            ->latest()->get();

        $allClients = Client::where('status', 'active')->orderBy('name')->get();
        $roles = Role::all();
        // Kandidat pengganti buat modal nonaktifkan - staff aktif lain,
        // dipopulasi selalu (bukan cuma pas ada yang nonaktifkan) biar
        // dropdown-nya nggak perlu request tambahan per baris.
        $replacementOptions = User::query()->where('status', 'active')->orderBy('name')->get();

        return view('user-management.index', compact('users', 'allClients', 'roles', 'replacementOptions', 'tab'));
    }

    /**
     * "Undang User" - buat person BARU sekaligus aktifkan akses login-nya
     * (undangan = login_enabled=true, beda dari roster GUIDE yang di-import
     * tanpa akses). Role many-to-many (RBAC multi-role) - satu user bisa
     * diundang langsung dengan beberapa role sekaligus.
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
            'login_enabled' => true,
        ]);
        $user->roles()->attach($validated['role_ids']);

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
     * KI-06 - satu-satunya jalur UI untuk memberi/mencabut akses login
     * (login_enabled), terpisah dari status aktif/nonaktif (lifecycle akun).
     * Dibutuhkan terutama buat staf roster GUIDE yang di-import dengan
     * login_enabled=false - sebelum ini tidak ada tombol manapun buat
     * mengaktifkannya, cuma bisa lewat database langsung.
     */
    public function toggleLoginAccess(User $user)
    {
        $this->authorizeManage();

        $user->update(['login_enabled' => ! $user->login_enabled]);

        $message = $user->login_enabled
            ? "Akses login {$user->name} berhasil diaktifkan - sekarang bisa masuk dengan Google."
            : "Akses login {$user->name} berhasil dicabut.";

        return back()->with('status', $message);
    }

    /**
     * "Edit Role" pada row Kelola Tim - mengedit user_roles (many-to-many,
     * RBAC multi-role) - satu User bisa punya beberapa role sekaligus.
     * Berlaku sama untuk User dengan atau tanpa login_enabled - jabatan
     * operasional TIDAK bergantung pada apakah orang itu bisa login.
     */
    public function updateRole(Request $request, User $user)
    {
        $this->authorizeManage();

        // Bag terpisah ('editRoles') - lihat catatan yang sama di store().
        $validated = $request->validateWithBag('editRoles', [
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user->roles()->sync($validated['role_ids']);

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
