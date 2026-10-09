<?php

namespace App\Console\Commands;

use App\Services\AutoBackupService;
use Illuminate\Console\Command;

class CheckAutoBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:auto-check {--force : Force backup execution regardless of frequency schedule}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if an automated database backup is overdue and execute it if necessary';

    /**
     * Execute the console command.
     */
    public function handle(AutoBackupService $service): int
    {
        $frequency = $service->getFrequency();
        $this->info("Auto-backup frequency is set to: {$frequency}");

        if ($this->option('force')) {
            $this->warn('Force flag enabled. Running database backup now...');
            $result = $this->call('backup:run', ['--only-db' => true]);

            return $result;
        }

        if ($frequency === AutoBackupService::FREQUENCY_DISABLED) {
            $this->line('Auto-backup is disabled. Skipping.');

            return self::SUCCESS;
        }

        $lastBackup = $service->getLastBackupTime();
        $this->line('Last backup timestamp: '.($lastBackup ? $lastBackup->toDateTimeString() : 'None found'));

        if (! $service->isOverdue()) {
            $nextDue = $service->getNextDueTime();
            $this->info('Backup is up to date. Next backup due: '.($nextDue ? $nextDue->toDateTimeString() : 'N/A'));

            return self::SUCCESS;
        }

        $this->warn('Backup is overdue! Triggering automated backup now...');
        $executed = $service->checkAndRunIfOverdue('console_command');

        if ($executed) {
            $this->info('Automated backup created successfully.');

            return self::SUCCESS;
        }

        $this->error('Automated backup could not be completed or failed.');

        return self::FAILURE;
    }
}
