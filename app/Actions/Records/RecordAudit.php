<?php

namespace App\Actions\Records;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAudit
{
    public function record(User $actor, Model $subject, string $action, array $before = [], ?string $reason = null): void
    {
        $after = $subject->only($subject->getFillable());
        if ($subject instanceof Student) {
            foreach (Student::PROTECTED_IDENTIFIERS as $key) {
                if (array_key_exists($key, $before)) {
                    $before[$key] = filled($before[$key]) ? '[REDACTED]' : null;
                }
                $after[$key] = filled($after[$key]) ? '[REDACTED]' : null;
            }
            $after['academic_program_name'] = $subject->academicProgram?->name;
            $after['academic_year'] = $subject->academicPeriod?->academic_year;
            $after['semester_code'] = $subject->academicPeriod?->semester_code;
        }
        AuditEvent::create([
            'actor_id' => $actor->id,
            'actor_snapshot' => ['name' => $actor->name, 'email' => $actor->email],
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'occurred_at' => now(),
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }
}
