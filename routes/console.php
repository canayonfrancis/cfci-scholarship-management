<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sms:grant-role {email} {role}', function () {
    $role = $this->argument('role');
    if (! in_array($role, User::OPERATIONAL_ROLES, true)) {
        $this->error('Supported roles: '.implode(', ', User::OPERATIONAL_ROLES));

        return 1;
    }
    $user = User::where('email', Str::lower(trim($this->argument('email'))))->first();
    if (! $user) {
        $this->error('No existing account has that email address.');

        return 1;
    }
    DB::transaction(function () use ($user, $role) {
        $membership = UserRole::firstOrCreate(['user_id' => $user->id, 'role' => $role]);
        if ($membership->wasRecentlyCreated) {
            AuditEvent::create([
                'actor_snapshot' => ['source' => 'trusted console operator'], 'action' => 'staff_role_granted',
                'subject_type' => UserRole::class, 'subject_id' => $membership->id, 'occurred_at' => now(),
                'after_state' => ['user_id' => $user->id, 'role' => $role],
            ]);
        }
    });
    $this->info('Granted '.$role.' to '.$user->email.'.');

    return 0;
})->purpose('Grant an explicit staff role to an existing account without changing credentials');
