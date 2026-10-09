<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'wrong-password');

    $component->call('login');

    $component
        ->assertHasErrors()
        ->assertNoRedirect();

    $this->assertGuest();
});

test('navigation menu can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response
        ->assertOk()
        ->assertSeeVolt('layout.navigation');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('layout.navigation');

    $component->call('logout');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
});

test('navigation sidebar retains collapsed and expanded state across page visits', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Initial mount: expanded by default
    $component = Volt::test('layout.navigation');
    expect($component->get('collapsed'))->toBeFalse();

    // Toggle sidebar to collapsed
    $component->call('toggleSidebar');
    expect($component->get('collapsed'))->toBeTrue();
    expect(session('sidebar_collapsed'))->toBeTrue();

    // Remounting component (simulating page navigation) should retain collapsed state
    $newComponent = Volt::test('layout.navigation');
    expect($newComponent->get('collapsed'))->toBeTrue();

    // Toggle back to expanded
    $newComponent->call('toggleSidebar');
    expect($newComponent->get('collapsed'))->toBeFalse();
    expect(session('sidebar_collapsed'))->toBeFalse();

    // Subsequent remount should retain expanded state
    $finalComponent = Volt::test('layout.navigation');
    expect($finalComponent->get('collapsed'))->toBeFalse();
});
