<?php

namespace App\Modules\Auth\DTOs;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\UserRole;

readonly class UserDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public UserRole $role,
        public bool $is_active,
        public ?string $last_login_at,
    ) {}

    public static function fromModel(User $user): static
    {
        return new static(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->role,
            is_active: $user->is_active,
            last_login_at: $user->last_login_at?->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
        ];
    }
}
