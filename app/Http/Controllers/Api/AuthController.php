<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $roleValue = $user->role instanceof UserRole ? $user->role->value : ($user->role ?? 'viewer');

        $token = $user->createToken('auth-token')->plainTextToken;

        AuditLogger::logUserAction(
            user: $user,
            event: 'user.registered',
            auditable: $user,
            payload: ['email' => $user->email, 'role' => $roleValue]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleValue,
                    'created_at' => $user->created_at?->toISOString(),
                ],
                'token' => $token,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $roleValue = $user->role instanceof UserRole ? $user->role->value : ($user->role ?? 'viewer');

        $token = $user->createToken('auth-token')->plainTextToken;

        AuditLogger::logUserAction(
            user: $user,
            event: 'user.login',
            auditable: $user,
            payload: ['email' => $user->email]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleValue,
                    'created_at' => $user->created_at?->toISOString(),
                ],
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()->delete();

            AuditLogger::logUserAction(
                user: $user,
                event: 'user.logout',
                auditable: $user
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Successfully logged out.',
            ],
        ]);
    }
}
