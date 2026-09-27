<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('admin can create a new staff account with secure password and role', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->set('createName', 'Juan Dela Cruz')
        ->set('createUsername', 'jdelacruz')
        ->set('createRole', 'mixer')
        ->set('createPassword', 'secret12345')
        ->set('createPassword_confirmation', 'secret12345')
        ->call('createUser')
        ->assertHasNoErrors();

    $newUser = User::where('username', 'jdelacruz')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('Juan Dela Cruz')
        ->and($newUser->role)->toBe('mixer')
        ->and($newUser->active)->toBeTrue()
        ->and(Hash::check('secret12345', $newUser->password))->toBeTrue();

    $audit = AuditLog::where('auditable_type', User::class)->where('auditable_id', $newUser->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('user_created');
});

test('admin can update user profile and reset credentials', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $staff = User::factory()->create(['username' => 'pedro', 'role' => 'mixer']);

    // Update user profile
    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->set('editingUserId', $staff->id)
        ->set('editName', 'Pedro Penduko')
        ->set('editUsername', 'ppenduko')
        ->set('editRole', 'manager')
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($staff->fresh()->name)->toBe('Pedro Penduko')
        ->and($staff->fresh()->username)->toBe('ppenduko')
        ->and($staff->fresh()->role)->toBe('manager');

    // Reset password
    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->set('resetUserId', $staff->id)
        ->set('newPassword', 'newpassword99')
        ->set('newPassword_confirmation', 'newpassword99')
        ->call('resetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('newpassword99', $staff->fresh()->password))->toBeTrue();
});

test('admin cannot deactivate own account but can toggle another staff member', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $anotherAdmin = User::factory()->create(['role' => 'admin']);
    $staff = User::factory()->create(['role' => 'mixer', 'active' => true]);

    // Admin attempts to deactivate self
    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->call('confirmToggleActive', $admin->id)
        ->assertDispatched('toast');

    expect($admin->fresh()->active)->toBeTrue();

    // Admin deactivates staff member
    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->set('toggleUserId', $staff->id)
        ->call('executeToggleActive')
        ->assertHasNoErrors();

    expect($staff->fresh()->active)->toBeFalse();

    // Reactivate staff member
    Volt::actingAs($admin)
        ->test('pages.user-access.index')
        ->set('toggleUserId', $staff->id)
        ->call('executeToggleActive')
        ->assertHasNoErrors();

    expect($staff->fresh()->active)->toBeTrue();
});
