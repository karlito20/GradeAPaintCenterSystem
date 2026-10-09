<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AutoBackupService
{
    public const FREQUENCY_DISABLED = 'disabled';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_EVERY_3_DAYS = 'every_3_days';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    /**
     * Supported auto-backup frequencies and readable labels.
     *
     * @return array<string, string>
     */
    public static function getFrequencies(): array
    {
        return [
            self::FREQUENCY_DISABLED => 'Disabled',
            self::FREQUENCY_DAILY => 'Daily (Every 24 Hours)',
            self::FREQUENCY_EVERY_3_DAYS => 'Every 3 Days (72 Hours)',
            self::FREQUENCY_WEEKLY => 'Weekly (Every 7 Days)',
            self::FREQUENCY_MONTHLY => 'Monthly (Every 30 Days)',
        ];
    }

    /**
     * Get the configured auto-backup frequency.
     */
    public function getFrequency(): string
    {
        return Setting::get('backup_frequency', self::FREQUENCY_DAILY);
    }

    /**
     * Set the auto-backup frequency.
     */
    public function setFrequency(string $frequency): void
    {
        if (! array_key_exists($frequency, self::getFrequencies())) {
            $frequency = self::FREQUENCY_DAILY;
        }

        Setting::set('backup_frequency', $frequency);
    }

    /**
     * Resolve the filesystem directory where backup archives are stored.
     */
    public function getBackupDir(): string
    {
        $backupName = config('backup.backup.name', env('APP_NAME', 'GradeAPaintCenterSystem'));
        $appDir = storage_path('app/private/'.$backupName);
        if (File::isDirectory($appDir)) {
            return $appDir;
        }

        $altDir = storage_path('app/'.$backupName);
        if (File::isDirectory($altDir)) {
            return $altDir;
        }

        File::ensureDirectoryExists($appDir);

        return $appDir;
    }

    /**
     * Get the timestamp of the most recent backup file.
     */
    public function getLastBackupTime(): ?CarbonImmutable
    {
        $dir = $this->getBackupDir();
        $latestMTime = null;

        if (File::isDirectory($dir)) {
            $files = File::files($dir);
            foreach ($files as $file) {
                if (strtolower($file->getExtension()) === 'zip') {
                    $mtime = $file->getMTime();
                    if ($latestMTime === null || $mtime > $latestMTime) {
                        $latestMTime = $mtime;
                    }
                }
            }
        }

        if ($latestMTime !== null) {
            return CarbonImmutable::createFromTimestamp($latestMTime);
        }

        $lastAutoRun = Setting::get('backup_last_auto_run');
        if ($lastAutoRun) {
            try {
                return CarbonImmutable::parse($lastAutoRun);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get the interval in seconds for the configured or given frequency.
     */
    public function getIntervalSeconds(?string $frequency = null): ?int
    {
        $frequency = $frequency ?? $this->getFrequency();

        return match ($frequency) {
            self::FREQUENCY_DAILY => 86400,          // 24 hours
            self::FREQUENCY_EVERY_3_DAYS => 259200,   // 72 hours
            self::FREQUENCY_WEEKLY => 604800,        // 7 days
            self::FREQUENCY_MONTHLY => 2592000,      // 30 days
            default => null,
        };
    }

    /**
     * Calculate when the next backup is scheduled to run.
     */
    public function getNextDueTime(): ?CarbonImmutable
    {
        $interval = $this->getIntervalSeconds();
        if ($interval === null) {
            return null; // Disabled
        }

        $lastBackup = $this->getLastBackupTime();
        if ($lastBackup === null) {
            return CarbonImmutable::now(); // Overdue immediately if no backup exists
        }

        return $lastBackup->addSeconds($interval);
    }

    /**
     * Determine if a backup is currently overdue.
     */
    public function isOverdue(): bool
    {
        $interval = $this->getIntervalSeconds();
        if ($interval === null) {
            return false; // Disabled
        }

        $lastBackup = $this->getLastBackupTime();
        if ($lastBackup === null) {
            return true; // No backup exists yet
        }

        return CarbonImmutable::now()->greaterThanOrEqualTo($lastBackup->addSeconds($interval));
    }

    /**
     * Check if a backup is overdue and trigger it automatically.
     */
    public function checkAndRunIfOverdue(string $source = 'system'): bool
    {
        if (! $this->isOverdue()) {
            return false;
        }

        // Use cache lock to prevent concurrent executions
        $lock = Cache::lock('auto_backup_running', 180);

        return (bool) $lock->get(function () use ($source) {
            if (! $this->isOverdue()) {
                return false;
            }

            try {
                Log::info("AutoBackupService: Triggering automated backup (Source: {$source})");

                $exitCode = Artisan::call('backup:run', ['--only-db' => true]);
                $output = trim(Artisan::output());

                Setting::set('backup_last_auto_run', CarbonImmutable::now()->toIso8601String());

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'event' => 'database_auto_backup_created',
                    'auditable_type' => 'Database',
                    'auditable_id' => null,
                    'context' => [
                        'source' => $source,
                        'exit_code' => $exitCode,
                        'output' => $output,
                        'frequency' => $this->getFrequency(),
                    ],
                ]);

                Log::info("AutoBackupService: Completed automated backup with exit code {$exitCode}");

                return $exitCode === 0;
            } catch (\Throwable $e) {
                Log::error('AutoBackupService: Automated backup failed: '.$e->getMessage(), [
                    'exception' => $e,
                ]);

                return false;
            }
        });
    }
}
