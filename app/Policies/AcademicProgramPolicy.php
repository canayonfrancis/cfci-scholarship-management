<?php

namespace App\Policies;

class AcademicProgramPolicy extends RecordPolicy
{
    // Existing staff may read; only Administrators maintain reference configuration.
}
