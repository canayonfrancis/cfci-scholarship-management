<?php

namespace App\Console\Commands;

use App\Services\Records\ScholarImports;
use Illuminate\Console\Command;

class CleanupScholarImports extends Command
{
    protected $signature = 'scholars:clean-imports';

    protected $description = 'Remove expired private scholar upload files without changing database records';

    public function handle(ScholarImports $imports): int
    {
        try {
            $count = $imports->cleanup();
            $this->info('Cleaned '.$count.' expired upload directories.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Upload cleanup could not finish. Check private storage permissions and application key configuration.');

            return self::FAILURE;
        }
    }
}
