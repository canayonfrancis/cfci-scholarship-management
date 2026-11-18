<?php

namespace App\Console\Commands;

use App\Actions\Accounts\LinkStudentAccount as LinkAccount;
use Illuminate\Console\Command;

class LinkStudentAccount extends Command
{
    protected $signature = 'sms:link-student {email : Existing account email} {student_number : Existing institutional Student ID}';

    protected $description = 'Link an existing account and student record with Student access (trusted console only)';

    public function handle(LinkAccount $link): int
    {
        $result = $link->fromTrustedConsole($this->argument('email'), $this->argument('student_number'));
        if (! $result->successful) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message);

        return self::SUCCESS;
    }
}
