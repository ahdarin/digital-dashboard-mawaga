<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $role = \App\Models\Role::where('name', 'Client Member')->first();

        if ($role) {
            $role->permissions()->detach();
            $role->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \App\Models\Role::firstOrCreate(['name' => 'Client Member']);
    }
};
