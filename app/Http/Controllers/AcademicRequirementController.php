<?php

namespace App\Http\Controllers;

/** Retired write action; retained to safely reject stale cached routes/clients. */
class AcademicRequirementController extends Controller
{
    public function store(): never
    {
        abort(404);
    }
}
