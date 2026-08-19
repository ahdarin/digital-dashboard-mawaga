<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['role_id', 'client_id', 'name', 'email', 'phone_number', 'google_id', 'avatar_url', 'password', 'status', 'preferences'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * 'light' | 'dark' | 'system' - lihat partials/_theme-init-script &
     * PreferencesController::updateTheme(). Default 'system' kalau belum
     * pernah diset (preferences null atau key theme belum ada).
     */
    public function themePreference(): string
    {
        return $this->preferences['theme'] ?? 'system';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ContentItemAssignment::class);
    }

    public function currentWorkflows(): HasMany
    {
        return $this->hasMany(ContentWorkflow::class, 'current_pic_id');
    }

    public function statusLogsChanged(): HasMany
    {
        return $this->hasMany(ContentStatusLog::class, 'changed_by');
    }

    public function magicLoginTokens(): HasMany
    {
        return $this->hasMany(MagicLoginToken::class);
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(Pin::class);
    }

    public function hasAnyRole(array $roles): bool
    {
        $roleValues = array_map(fn (UserRole $r) => $r->value, $roles);
        return in_array($this->role?->name, $roleValues, true);
    }

    public function hasPermissionTo(string $module, string $action): bool
    {
        return $this->role?->hasPermission($module, $action) ?? false;
    }

    public function isClientUser(): bool
    {
        return !is_null($this->client_id);
    }

    public function clientAssignments(): HasMany
    {
        return $this->hasMany(UserClientAssignment::class);
    }

    public function assignedClients()
    {
        return $this->belongsToMany(Client::class, 'user_client_assignments');
    }

    public function canSeeAllClients(): bool
    {
        return $this->hasAnyRole([UserRole::CEO, UserRole::Manager]);
    }
}