<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function hasPermission(string $module, string $action): bool
    {
        // Akses lewat property (bukan permissions()) biar relasinya cuma
        // di-query sekali per instance Role lalu di-cache Eloquent - sidebar
        // manggil ini banyak kali per render, dulu tiap panggilan bikin
        // query baru ke role_permissions.
        return $this->permissions->contains(
            fn (Permission $permission) => $permission->module === $module && $permission->action === $action
        );
    }
}