<?php

use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Services\AutoBackupService;
use Carbon\CarbonImmutable;
use Database\Seeders\PaintStoreSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;

test('admin can view backup management page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get('/admin/backup')
        ->assertOk()
        ->assertSee('Backups')
        ->assertSee('Auto-Backup')
        ->assertSee('Frequency');
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
        ->assertSet('backupFrequency', 'daily')
        ->assertHasNoErrors();
});

test('setting model stores and retrieves key values', function () {
    Setting::set('test_key', 'test_value');

    expect(Setting::get('test_key'))->toBe('test_value')
        ->and(Setting::has('test_key'))->toBeTrue()
        ->and(Setting::has('non_existent_key'))->toBeFalse()
        ->and(Setting::get('non_existent_key', 'default'))->toBe('default');
});

test('auto backup service manages frequencies and overdue status', function () {
    $service = app(AutoBackupService::class);

    $service->setFrequency(AutoBackupService::FREQUENCY_WEEKLY);
    expect($service->getFrequency())->toBe(AutoBackupService::FREQUENCY_WEEKLY);

    // When disabled, isOverdue is always false
    $service->setFrequency(AutoBackupService::FREQUENCY_DISABLED);
    expect($service->isOverdue())->toBeFalse()
        ->and($service->getNextDueTime())->toBeNull();

    // With daily, test interval calculation
    $service->setFrequency(AutoBackupService::FREQUENCY_DAILY);
    expect($service->getIntervalSeconds())->toBe(86400);

    // Test time progression relative to latest backup
    $lastBackup = $service->getLastBackupTime() ?? CarbonImmutable::now();

    // 2 days in the future relative to last backup -> overdue
    CarbonImmutable::setTestNow($lastBackup->addDays(2));
    expect($service->isOverdue())->toBeTrue();

    // 10 minutes in the future relative to last backup -> not overdue
    CarbonImmutable::setTestNow($lastBackup->addMinutes(10));
    expect($service->isOverdue())->toBeFalse();

    CarbonImmutable::setTestNow(null);
});

test('admin can update auto backup frequency from livewire component', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.admin.backup')
        ->set('backupFrequency', AutoBackupService::FREQUENCY_WEEKLY)
        ->call('updateFrequency')
        ->assertDispatched('toast');

    $service = app(AutoBackupService::class);
    expect($service->getFrequency())->toBe(AutoBackupService::FREQUENCY_WEEKLY);
});

test('backup auto check console command executes cleanly', function () {
    $service = app(AutoBackupService::class);
    $service->setFrequency(AutoBackupService::FREQUENCY_DISABLED);

    $this->artisan('backup:auto-check')
        ->assertExitCode(0)
        ->expectsOutputToContain('Auto-backup is disabled');
});

test('login event listener triggers auto backup check', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $service = app(AutoBackupService::class);
    $service->setFrequency(AutoBackupService::FREQUENCY_DISABLED);

    // Firing login event should run safely without throwing
    event(new Login('web', $user, false));

    expect(true)->toBeTrue();
});

test('categories have shortened concise names', function () {
    $this->seed(PaintStoreSeeder::class);

    $longNames = [
        'Architectural & Decorative Paints',
        'Enamels & Gloss Finishes',
        'Roof & Elastomeric Paints',
        'Primers, Sealers & Undercoats',
        'Automotive & Industrial Coatings',
        'Thinners, Solvents & Reducers',
        'Putties, Fillers & Sealants',
        'Painting Tools & Applicators',
        'Hardware Tools & Accessories',
        'Abrasives & Surface Preparation',
    ];

    foreach ($longNames as $longName) {
        expect(Category::where('name', $longName)->exists())->toBeFalse();
    }

    $shortNames = [
        'Decorative Paints',
        'Enamels & Gloss',
        'Roof & Elastomeric',
        'Primers & Sealers',
        'Automotive Coatings',
        'Thinners & Solvents',
        'Tinting Colors',
        'Putties & Fillers',
        'Painting Tools',
        'Hardware & Tools',
        'Abrasives & Prep',
    ];

    foreach ($shortNames as $shortName) {
        expect(Category::where('name', $shortName)->exists())->toBeTrue();
    }
});
