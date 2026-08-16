<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'Client Member')->value('id');

        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }

    /**
     * Reverse the migrations. Nggak reversible dengan aman - permission
     * set aslinya udah dihapus dari PermissionSeeder juga di commit yang
     * sama, jadi tidak ada sumber kebenaran buat merekonstruksinya. Baris
     * role-nya dikembalikan (biar FK yang mungkin masih menunjuk ke role
     * ini nggak orphan), tapi tanpa permission apa pun terpasang.
     */
    public function down(): void
    {
        if (! DB::table('roles')->where('name', 'Client Member')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Client Member',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
