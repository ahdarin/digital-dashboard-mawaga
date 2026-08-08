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
        DB::table('roles')->where('name', 'Admin')->update(['name' => 'Manager']);
        DB::table('roles')->where('name', 'MSO')->update(['name' => 'SMO']);

        if (! DB::table('roles')->where('name', 'Copywriter')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Copywriter',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('name', 'Manager')->update(['name' => 'Admin']);
        DB::table('roles')->where('name', 'SMO')->update(['name' => 'MSO']);
        DB::table('roles')->where('name', 'Copywriter')->delete();
    }
};
