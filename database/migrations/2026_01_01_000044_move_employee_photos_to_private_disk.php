<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Badge photos and CIN (national ID card) scans used to be stored on the `public` disk, i.e.
// served by the web server at /storage/... to anyone with the URL, no login needed. They now
// live on the private `local` disk (EmployeeController::PHOTO_DISK) and are only served through
// the authorised employees.photo route. This moves the files already uploaded; the relative
// paths stored in employees.photo_path/cin_photo_path stay the same, only the disk changes.
//
// Idempotent: a file already on the private disk is left alone, so re-running is safe.
return new class extends Migration
{
    public function up(): void
    {
        $this->move('public', 'local');
    }

    public function down(): void
    {
        $this->move('local', 'public');
    }

    private function move(string $from, string $to): void
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);

        $paths = DB::table('employees')->pluck('photo_path')
            ->merge(DB::table('employees')->pluck('cin_photo_path'))
            ->filter();

        foreach ($paths as $path) {
            if (! $source->exists($path) || $target->exists($path)) {
                continue;
            }

            $target->writeStream($path, $source->readStream($path));
            $source->delete($path);
        }
    }
};
