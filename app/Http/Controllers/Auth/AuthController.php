<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inicia sesión y devuelve un token de Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas.'],
            ]);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Devuelve el usuario autenticado con sus roles y permisos.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Revoca todos los tokens del usuario.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Todas las sesiones fueron cerradas.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}
