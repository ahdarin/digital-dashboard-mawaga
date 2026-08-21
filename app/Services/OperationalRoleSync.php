<?php

namespace App\Services;

use App\Models\Role;
use App\Models\TeamMember;

/**
 * Akses sistem (users.roles) mengikuti TeamMember.operational_role - SATU
 * role, bukan dua sistem yang berjalan independen (keputusan eksplisit
 * user: "gabungkan sistem role ini dengan operational role karena tetap
 * saja sama fungsinya"). operational_role adalah satu-satunya input; system
 * role tinggal REFLEKSI otomatisnya, tidak pernah diedit terpisah lagi.
 *
 * TANPA PENGECUALIAN - percobaan sebelumnya mengecualikan 3 akun bootstrap
 * (Ahda/Surdik/Ghazi) supaya mereka tetap CEO terlepas dari operational_role.
 * Sekarang tidak perlu lagi: TeamMember DIKA/GHAZI/Ahda ketiganya sudah
 * di-set operational_role="CEO" secara eksplisit (bukan "Desain Grafis"/
 * "Content Creator" lagi), jadi sync apa adanya sudah otomatis
 * menghasilkan CEO untuk mereka - tidak butuh exception di kode.
 *
 * Dipanggil otomatis lewat TeamMemberObserver (app/Observers) setiap kali
 * operational_role atau user_id berubah - bukan cuma dari 1-2 titik manual,
 * biar invarian "1 role" selalu terjaga di jalur mana pun perubahan terjadi.
 */
class OperationalRoleSync
{
    /** operational_role (TeamMember, Indonesia) => nama Role sistem (users.roles). */
    private const MAP = [
        'CEO' => 'CEO',
        'Manager' => 'Manager',
        'Desain Grafis' => 'Graphic Designer',
        'SMO' => 'SMO',
        'Content Creator' => 'Content Creator',
    ];

    /**
     * Sinkronkan system role User yang terhubung ke operational_role
     * TeamMember ini - REPLACE (bukan tambah) roles existing, karena akses
     * sistem sekarang sepenuhnya mengikuti role kerja, bukan akumulasi.
     * No-op kalau: tidak ada User terhubung, operational_role belum di-set,
     * atau operational_role-nya tidak punya padanan system role (seharusnya
     * tidak terjadi - MAP mencakup seluruh 5 nilai kanonikal operational_role).
     */
    public function apply(TeamMember $teamMember): void
    {
        if (! $teamMember->user_id || ! $teamMember->operational_role) {
            return;
        }

        $user = $teamMember->user ?? $teamMember->user()->first();
        if (! $user) {
            return;
        }

        $systemRoleName = self::MAP[$teamMember->operational_role] ?? null;
        if (! $systemRoleName) {
            return;
        }

        $role = Role::where('name', $systemRoleName)->first();
        if ($role) {
            $user->roles()->sync([$role->id]);
        }
    }
}
