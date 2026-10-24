<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Dumps the MySQL database to <backup.path>/<database>_<date>_<time>.sql.gz and keeps only the
 * newest <backup.keep>. The password goes to mysqldump through MYSQL_PWD, never on its command
 * line. A failed dump leaves no file behind and fails the command, so the log shows it.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep= : how many backups to keep (default: config backup.keep)}';

    protected $description = 'Back up the database (gzipped mysqldump) and delete the oldest backups';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'mysql') {
            $this->error('db:backup ne gère que MySQL.');

            return self::FAILURE;
        }

        $dir = rtrim(config('backup.path'), '/\\');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            $this->error("Dossier de sauvegarde inaccessible : {$dir}");

            return self::FAILURE;
        }

        $database = $connection['database'];
        $file = $dir.DIRECTORY_SEPARATOR.$database.'_'.now()->format('Y-m-d_His').'.sql.gz';
        $partial = $file.'.part';

        $process = new Process([
            config('backup.mysqldump'),
            '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'],
            '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
            $database,
        ], null, ['MYSQL_PWD' => (string) $connection['password']], null, 600);

        $gz = gzopen($partial, 'wb6');
        $errors = '';
        $process->run(function ($type, $buffer) use ($gz, &$errors) {
            $type === Process::OUT ? gzwrite($gz, $buffer) : $errors .= $buffer;
        });
        gzclose($gz);

        if (! $process->isSuccessful()) {
            @unlink($partial);
            $this->error('Sauvegarde échouée : '.trim($errors ?: $process->getErrorOutput()));

            return self::FAILURE;
        }
        rename($partial, $file);
        $this->info('Sauvegarde : '.$file.' ('.round(filesize($file) / 1024).' Ko)');

        $this->prune($dir, $database, (int) ($this->option('keep') ?? config('backup.keep')));

        return self::SUCCESS;
    }

    /** Keeps the newest $keep backups of $database in $dir (names sort by date). */
    private function prune(string $dir, string $database, int $keep): void
    {
        $backups = glob($dir.DIRECTORY_SEPARATOR.$database.'_*.sql.gz') ?: [];
        rsort($backups);
        foreach (array_slice($backups, max(1, $keep)) as $old) {
            unlink($old);
            $this->line('Supprimée (ancienne) : '.basename($old));
        }
    }
}
