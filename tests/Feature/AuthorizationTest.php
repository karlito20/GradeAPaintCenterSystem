<?php

use App\Models\User;

test('superadmin role has full access to all sections including troubleshooting', function () {
    $superadmin = User::factory()->create(['role' => 'superadmin']);

    $this->actingAs($superadmin)->get('/sales')->assertOk();
    $this->actingAs($superadmin)->get('/inventory')->assertOk();
    $this->actingAs($superadmin)->get('/audit')->assertOk();
    $this->actingAs($superadmin)->get('/user-access')->assertOk();
    $this->actingAs($superadmin)->get('/admin/backup')->assertOk();
    $this->actingAs($superadmin)->get('/dev/troubleshooting')->assertOk();
    $this->actingAs($superadmin)->get('/settings/brands')->assertOk();
});

test('admin role has access to administrative and settings but is forbidden from dev troubleshooting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/sales')->assertOk();
    $this->actingAs($admin)->get('/inventory')->assertOk();
    $this->actingAs($admin)->get('/audit')->assertOk();
    $this->actingAs($admin)->get('/user-access')->assertOk();
    $this->actingAs($admin)->get('/admin/backup')->assertOk();
    $this->actingAs($admin)->get('/settings/brands')->assertOk();
    $this->actingAs($admin)->get('/dev/troubleshooting')->assertForbidden();
});

test('manager role has operational access but is forbidden from administrative and settings', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)->get('/sales')->assertOk();
    $this->actingAs($manager)->get('/inventory')->assertOk();
    $this->actingAs($manager)->get('/inventory/stock-in')->assertOk();
    $this->actingAs($manager)->get('/inventory/physical-count')->assertOk();

    $this->actingAs($manager)->get('/audit')->assertForbidden();
    $this->actingAs($manager)->get('/user-access')->assertForbidden();
    $this->actingAs($manager)->get('/admin/backup')->assertForbidden();
    $this->actingAs($manager)->get('/dev/troubleshooting')->assertForbidden();
    $this->actingAs($manager)->get('/settings/brands')->assertForbidden();
    $this->actingAs($manager)->get('/settings/categories')->assertForbidden();
    $this->actingAs($manager)->get('/settings/package-units')->assertForbidden();
});

test('mixer role can access sales, movements, and quotations but is forbidden from administrative, settings, and sales history', function () {
    $mixer = User::factory()->create(['role' => 'mixer']);

    $this->actingAs($mixer)->get('/sales')->assertOk();
    $this->actingAs($mixer)->get('/sales/quotations')->assertOk();
    $this->actingAs($mixer)->get('/inventory/movements')->assertOk();

    $this->actingAs($mixer)->get('/sales/history')->assertForbidden();
    $this->actingAs($mixer)->get('/audit')->assertForbidden();
    $this->actingAs($mixer)->get('/user-access')->assertForbidden();
    $this->actingAs($mixer)->get('/admin/backup')->assertForbidden();
    $this->actingAs($mixer)->get('/dev/troubleshooting')->assertForbidden();
    $this->actingAs($mixer)->get('/settings/brands')->assertForbidden();
});

test('sales history can be viewed by superadmin, admin, and manager but not mixer', function () {
    $superadmin = User::factory()->create(['role' => 'superadmin']);
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);
    $mixer = User::factory()->create(['role' => 'mixer']);

    $this->actingAs($superadmin)->get('/sales/history')->assertOk();
    $this->actingAs($admin)->get('/sales/history')->assertOk();
    $this->actingAs($manager)->get('/sales/history')->assertOk();
    $this->actingAs($mixer)->get('/sales/history')->assertForbidden();
});
