<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\Role;
use App\Models\TeamMember;
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
     * Canonical operational role values (Langkah "Final Fix Batch - revisi
     * ROLE") - dropdown tetap, bukan free text, biar konsisten dengan 13
     * TeamMember real yang sudah di-backfill dengan salah satu nilai ini.
     */
    private const OPERATIONAL_ROLES = ['CEO', 'Manager', 'Desain Grafis', 'SMO', 'Content Creator'];

    public function index(Request $request)
    {
        $this->authorizeManage();

        // SATU tabel, TeamMember sebagai primary dataset (Langkah "Final Fix
        // Batch - revisi ROLE") - bukan lagi 2 tab Tim/Akun Sistem. Role
        // ditampilkan dari TeamMember.operational_role (role operasional
        // real), BUKAN users.roles (itu domain authorization terpisah -
        // TeamMember boleh tidak punya User sama sekali). Client Ditangani
        // pakai team_member_client, bukan user_client_assignments.
        $teamMembers = TeamMember::with([
            'clients',
            'user' => fn ($q) => $q->with(['roles', 'assignedClients'])
                ->withCount(['currentWorkflows as active_task_count' => fn ($qq) => $qq->whereNotIn('current_status', WorkflowTransitions::DONE_STATUSES)]),
        ])->orderBy('name')->get();

        $allClients = Client::where('status', 'active')->orderBy('name')->get();
        $roles = Role::whereNotIn('name', ['Client Owner'])->get();
        // Kandidat pengganti buat modal nonaktifkan - staff aktif lain,
        // dipopulasi selalu (bukan cuma pas ada yang nonaktifkan) biar
        // dropdown-nya nggak perlu request tambahan per baris.
        $replacementOptions = User::whereNull('client_id')->where('status', 'active')->orderBy('name')->get();

        return view('user-management.index', compact('teamMembers', 'allClients', 'roles', 'replacementOptions'));
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

    /**
     * "Edit Role" pada row Kelola Tim - mengedit TeamMember.operational_role
     * (jabatan operasional), satu-satunya role yang ada sekarang (Langkah
     * "Gabungkan sistem role dengan operational role" - keputusan eksplisit
     * user). System role User terkait (kalau ada) otomatis ikut berubah
     * lewat TeamMemberObserver, bukan dipanggil manual di sini - biar
     * sinkronisasinya berlaku di jalur manapun operational_role berubah,
     * bukan cuma dari controller ini.
     */
    public function updateOperationalRole(Request $request, TeamMember $teamMember)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'operational_role' => ['required', Rule::in(self::OPERATIONAL_ROLES)],
        ]);

        $teamMember->update(['operational_role' => $validated['operational_role']]);

        $message = "Role {$teamMember->name} berhasil diperbarui.";
        if ($teamMember->user_id) {
            $message .= ' Akses sistem ikut disesuaikan.';
        }

        return back()->with('status', $message);
    }

    /**
     * "Assign Klien" pada row Kelola Tim - mengedit team_member_client
     * (client yang ditangani TeamMember), BUKAN user_client_assignments
     * (Langkah "Kelola Tim Actions fix"). team_member_client adalah source
     * of truth operasional saat ini - JANGAN fuzzy/manual reconstruction
     * dari XLSX di sini, cukup exists-validated client_ids dari form.
     *
     * Kalau TeamMember ini linked ke User DAN User itu BUKAN CEO/Manager
     * (global access) - sinkronkan juga user_client_assignments ke client
     * IDs yang sama, biar visibilitas dashboard User itu nggak divergen
     * dari tanggung jawab operasional TeamMember-nya. CEO/Manager sengaja
     * dikecualikan - mereka sudah canSeeAllClients()=true, fabricate
     * assignedClients buat mereka cuma bikin data yang nggak ada efeknya.
     */
    public function updateTeamMemberClients(Request $request, TeamMember $teamMember)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'client_ids' => 'array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $clientIds = $validated['client_ids'] ?? [];

        $teamMember->clients()->sync($clientIds);

        if ($teamMember->user_id && ! $teamMember->user->canSeeAllClients()) {
            $teamMember->user->assignedClients()->sync($clientIds);
        }

        return back()->with('status', "Client yang ditangani {$teamMember->name} berhasil diperbarui.");
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}