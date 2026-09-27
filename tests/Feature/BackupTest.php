<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('admin can view backup management page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get('/admin/backup')
        ->assertOk()
        ->assertSee('Database Backup & Restore');
});

test('manager cannot access backup management', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)
        ->get('/admin/backup')
        ->assertForbidden();
});

test('admin can initialize backup component', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.admin.backup')
        ->assertSet('restoringFile', null)
        ->assertSet('deletingFile', null)
        ->assertHasNoErrors();
});
