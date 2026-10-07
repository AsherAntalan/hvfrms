<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup extends Command
{
    protected $signature = 'db:backup';
    protected $description = 'Create a timestamped database backup (MySQL only)';

    public function handle(): int
    {
        $driver = config('database.default');
        if ($driver !== 'mysql') {
            $this->error("Backup is only supported for MySQL. Current driver: {$driver}");
            return self::FAILURE;
        }

        $db = config('database.connections.mysql');
        $timestamp = now()->format('Y-m-d_His');
        $filename = "hvfrms_backup_{$timestamp}.sql";
        $dir = storage_path('app/backups');

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $path = "{$dir}/{$filename}";

        $host = escapeshellarg($db['host']);
        $port = escapeshellarg($db['port'] ?? '3306');
        $user = escapeshellarg($db['username']);
        $pass = $db['password'];
        $database = escapeshellarg($db['database']);

        $cmd = "mysqldump -h {$host} -P {$port} -u {$user}"
             . ($pass ? " -p" . escapeshellarg($pass) : "")
             . " --single-transaction --routines --triggers {$database}"
             . " > " . escapeshellarg($path) . " 2>&1";

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $errorMsg = implode("\n", $output);
            $this->error("Backup failed: {$errorMsg}");

            AuditLog::create([
                'user_id'    => null,
                'action'     => 'database backup failed',
                'table_name' => 'system',
                'record_id'  => 0,
                'details'    => "Backup failed: {$errorMsg}",
            ]);

            return self::FAILURE;
        }

        $size = filesize($path);
        $sizeStr = $size > 1048576
            ? round($size / 1048576, 2) . ' MB'
            : round($size / 1024, 2) . ' KB';

        $this->info("Backup created successfully!");
        $this->info("File: {$path}");
        $this->info("Size: {$sizeStr}");

        AuditLog::create([
            'user_id'    => null,
            'action'     => 'database backup',
            'table_name' => 'system',
            'record_id'  => 0,
            'details'    => "Backup: {$filename} ({$sizeStr})",
        ]);

        return self::SUCCESS;
    }
}
