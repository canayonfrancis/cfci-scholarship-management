<?php

namespace App\Http\Controllers;

use App\Actions\Records\UpdateOwnStudentProfile;
use App\Http\Requests\StudentProfileUpdateRequest;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StudentProfileController extends Controller
{
    public function show(Request $request): View
    {
        Gate::authorize('viewSelf', Student::class);
        $student = $request->user()->student()->with(['academicProgram', 'academicPeriod'])->first();
        if ($student !== null) {
            Gate::authorize('viewOwnProfile', $student);
        }

        return view('student.profile', compact('student'));
    }

    public function edit(Request $request): View
    {
        Gate::authorize('viewSelf', Student::class);
        $student = $request->user()->student()->with(['academicProgram', 'academicPeriod'])->first();
        abort_unless($student, 403, 'No Student profile is linked to this account.');
        Gate::authorize('updateOwnProfile', $student);

        return view('student.edit', [
            'student' => $student, 'profileVersion' => UpdateOwnStudentProfile::version($student),
            'loginEmail' => $request->user()->email,
        ]);
    }

    public function update(StudentProfileUpdateRequest $request, UpdateOwnStudentProfile $profiles): RedirectResponse
    {
        try {
            $changed = $profiles->execute($request->user(), $request->validated());
        } catch (ValidationException $error) {
            if ($request->expectsJson()) {
                throw $error;
            }
            $response = to_route('student.profile.edit', [], 303)->withErrors($error->errors());

            return array_key_exists('profile_version', $error->errors()) ? $response->withInput([]) : $response->withInput($request->only(UpdateOwnStudentProfile::FIELDS));
        } catch (AuthorizationException|HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            report(new \RuntimeException('Student profile update failed; no private field values were recorded.'));

            return to_route('student.profile.edit', [], 303)->withErrors(['profile' => 'Your changes could not be saved. Review your profile and try again.']);
        }

        return to_route('student.profile', [], 303)->with('status', $changed ? 'Your Student information has been updated.' : 'No changes to save. Your information is already up to date.');
    }
}
