<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\DTOs\UserDTO;
use App\Modules\Auth\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !$user->is_active) {
            throw new AuthenticationException('Kredensial tidak valid atau akun tidak aktif.');
        }

        if (!Hash::check($password, $user->password)) {
            throw new AuthenticationException('Kredensial tidak valid atau akun tidak aktif.');
        }

        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken('api-token');

        return [
            'token' => $token->plainTextToken,
            'user' => UserDTO::fromModel($user),
            'expires_at' => null,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function me(User $user): UserDTO
    {
        return UserDTO::fromModel($user);
    }
}
