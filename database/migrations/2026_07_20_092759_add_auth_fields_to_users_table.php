<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Guarded with hasColumn checks: an earlier partial run of this migration
        // (aborted by the now-fixed ->after('is_active') bug) may have already
        // added some of these columns without being recorded as run.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('users', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('role_id')->constrained('clients')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('pending')->after('client_id');
                // status: pending, invited, active, inactive, rejected
            }
        });

        $indexes = collect(DB::select('SHOW INDEX FROM users'))->pluck('Key_name');
        if (! $indexes->contains('users_phone_number_unique')) {
            Schema::table('users', fn (Blueprint $table) => $table->unique('phone_number'));
        }
        if (! $indexes->contains('users_google_id_unique')) {
            Schema::table('users', fn (Blueprint $table) => $table->unique('google_id'));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['phone_number', 'google_id', 'status']);
        });
    }
};