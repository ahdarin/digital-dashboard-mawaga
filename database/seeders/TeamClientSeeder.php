<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Pengganti ContentPlannerSeeder/ContentPlannerPrerequisiteSeeder untuk
 * instalasi baru - dua seeder itu ikut membawa 247 ContentItem historis +
 * 19 ContentPlan bulanan dari Excel lama, yang ternyata tidak efektif untuk
 * instalasi baru (jadi penuh data lampau yang tidak relevan lagi).
 *
 * Seeder ini HANYA membawa staf tim + Client asli - satu-satunya bagian dari
 * data lama yang masih relevan dipakai terus - dari fixture
 * `data/team_and_clients.php`. Instalasi baru jadi mulai dengan roster +
 * daftar client nyata, tapi tanpa histori Content Plan/Content Item sama
 * sekali ("kosongan" seperti diminta).
 *
 * Prasyarat: RoleSeeder + MasterDataSeeder harus sudah jalan (butuh Role
 * & ClientCategory sudah ada) - keduanya sudah dipanggil DatabaseSeeder
 * sebelum seeder ini.
 *
 * Idempotent: Client di-firstOrCreate by name, User di-firstOrCreate by
 * email, role/client assignment di-syncWithoutDetaching - aman dijalankan
 * berkali-kali.
 */
class TeamClientSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = require __DIR__.'/data/team_and_clients.php';

        $categoryIds = ClientCategory::pluck('id', 'name');

        foreach ($fixture['clients'] as $row) {
            $categoryId = $categoryIds[$row['category']] ?? null;
            abort_unless($categoryId, 500, "ClientCategory '{$row['category']}' tidak ditemukan - jalankan MasterDataSeeder dulu.");

            Client::firstOrCreate(
                ['name' => $row['name']],
                ['client_category_id' => $categoryId, 'status' => 'active']
            );
        }

        $this->command?->info(count($fixture['clients']).' Client siap (dibuat baru kalau belum ada).');

        $clientIds = Client::pluck('id', 'name');
        $roleIds = Role::pluck('id', 'name');

        foreach ($fixture['users'] as $row) {
            $user = User::firstOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'login_enabled' => $row['login_enabled'],
                    'source' => $row['source'],
                ]
            );

            if ($row['role']) {
                $roleId = $roleIds[$row['role']] ?? null;
                abort_unless($roleId, 500, "Role '{$row['role']}' tidak ditemukan - jalankan RoleSeeder dulu.");
                $user->roles()->syncWithoutDetaching([$roleId]);
            }

            $assignClientIds = collect($row['clients'])
                ->map(fn ($name) => $clientIds[$name] ?? null)
                ->filter()
                ->values();

            if ($assignClientIds->isNotEmpty()) {
                $user->assignedClients()->syncWithoutDetaching($assignClientIds->all());
            }
        }

        $this->command?->info(count($fixture['users']).' User siap (dibuat baru kalau belum ada).');
    }
}
