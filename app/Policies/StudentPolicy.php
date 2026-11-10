<?php

namespace App\Policies;

class StudentPolicy extends RecordPolicy
{
    protected const WRITERS = ['administrator', 'registrar', 'coordinator'];
}
