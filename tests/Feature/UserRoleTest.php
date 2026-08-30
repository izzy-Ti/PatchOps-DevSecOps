<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user defaults to viewer role in factory', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::VIEWER)
        ->and($user->isViewer())->toBeTrue()
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->isSecurity())->toBeFalse()
        ->and($user->isDeveloper())->toBeFalse();
});

test('user factory states assign correct roles', function () {
    $admin = User::factory()->admin()->create();
    $security = User::factory()->security()->create();
    $developer = User::factory()->developer()->create();
    $viewer = User::factory()->viewer()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->role)->toBe(UserRole::ADMIN)
        ->and($security->isSecurity())->toBeTrue()
        ->and($security->role)->toBe(UserRole::SECURITY)
        ->and($developer->isDeveloper())->toBeTrue()
        ->and($developer->role)->toBe(UserRole::DEVELOPER)
        ->and($viewer->isViewer())->toBeTrue()
        ->and($viewer->role)->toBe(UserRole::VIEWER);
});

test('hasRole checks multiple roles correctly', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasRole(UserRole::ADMIN))->toBeTrue()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasRole(UserRole::SECURITY, UserRole::ADMIN))->toBeTrue()
        ->and($admin->hasRole('security', 'admin'))->toBeTrue()
        ->and($admin->hasRole(UserRole::SECURITY, UserRole::DEVELOPER))->toBeFalse()
        ->and($admin->hasRole('security', 'developer'))->toBeFalse();
});
