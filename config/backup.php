<?php

// Daily database backups (php artisan db:backup, scheduled in App\Console\Kernel and, on the
// office PC, by a Windows scheduled task). Keep them off the project folder — ideally on another
// disk or a synced folder — since they hold the workers' CIN and RIB.
return [
    'path' => env('BACKUP_PATH', storage_path('backups')),

    // mysqldump isn't always on PATH (Laragon keeps it in its own bin folder).
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    // How many backups to keep, newest first; older ones are deleted after each backup.
    'keep' => (int) env('BACKUP_KEEP', 30),
];
