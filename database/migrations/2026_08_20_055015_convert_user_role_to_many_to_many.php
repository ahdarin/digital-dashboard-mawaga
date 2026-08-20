<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        // Pindahkan role_id yang ada sekarang ke user_roles sebelum kolomnya
        // didrop - biar user yang sudah punya role tidak kehilangan aksesnya.
        $now = now();
        DB::table('users')->whereNotNull('role_id')->select('id', 'role_id')->orderBy('id')
            ->each(function ($user) use ($now) {
                DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $user->role_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained('roles');
        });

        DB::table('user_roles')->orderBy('id')->get()->groupBy('user_id')->each(function ($rows) {
            DB::table('users')->where('id', $rows->first()->user_id)->update(['role_id' => $rows->first()->role_id]);
        });

        Schema::dropIfExists('user_roles');
    }
};
