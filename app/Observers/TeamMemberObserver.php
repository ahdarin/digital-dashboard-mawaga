<?php

namespace App\Observers;

use App\Models\TeamMember;
use App\Services\OperationalRoleSync;

/**
 * "Satu role, bukan dua sistem terpisah" (keputusan eksplisit user) -
 * setiap kali operational_role atau user_id TeamMember berubah lewat jalur
 * MANAPUN (UI Kelola Tim, importer, tinker/console, fitur baru nanti),
 * system role User yang terhubung otomatis disamakan - bukan cuma dipicu
 * manual dari 1-2 controller/command tertentu.
 *
 * created()+updated() dipakai TERPISAH (bukan cuma saved()) karena
 * wasChanged() TERBUKTI kosong tepat setelah create() di versi Eloquent ini
 * (diverifikasi langsung: getChanges()===[] persis setelah
 * TeamMember::create() walau atribut jelas baru di-set) - kalau cuma pakai
 * saved()+wasChanged(), TeamMember baru yang langsung dibuat dengan
 * operational_role+user_id terisi (mis. dari importer) tidak akan ke-sync.
 */
class TeamMemberObserver
{
    public function created(TeamMember $teamMember): void
    {
        app(OperationalRoleSync::class)->apply($teamMember);
    }

    public function updated(TeamMember $teamMember): void
    {
        if ($teamMember->wasChanged('operational_role') || $teamMember->wasChanged('user_id')) {
            app(OperationalRoleSync::class)->apply($teamMember);
        }
    }
}
