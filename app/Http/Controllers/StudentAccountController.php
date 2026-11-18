<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\LinkStudentAccount;
use App\Actions\Accounts\StudentAccountLifecycle;
use App\Actions\Accounts\StudentCredentials;
use App\Http\Requests\CreateStudentAccountRequest;
use App\Http\Requests\ManageStudentAccountRequest;
use App\Http\Requests\SetStudentInitialPasswordRequest;
use App\Models\Student;
use App\Services\Accounts\StudentAccountDirectory;
use App\Support\TablePagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StudentAccountController extends Controller
{
    public function index(Request $request, StudentAccountDirectory $directory): View
    {
        Gate::authorize('student-accounts.provision');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_status' => ['nullable', Rule::in(array_keys(StudentAccountDirectory::STATUSES))],
        ]);
        $words = preg_split('/\s+/u', mb_strtolower(trim($filters['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $rows = $directory->rows($request->user())->filter(function ($row) use ($filters, $words) {
            if (filled($filters['account_status'] ?? null) && $row->status !== $filters['account_status']) {
                return false;
            }
            $text = mb_strtolower(implode(' ', [$row->studentNumber, $row->name, $row->contactEmail, $row->loginEmail]));
            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    return false;
                }
            }

            return true;
        });

        return view('student-accounts.index', [
            'students' => TablePagination::collection($rows, 'student_accounts'),
            'filters' => $filters, 'statuses' => StudentAccountDirectory::STATUSES,
        ]);
    }

    public function show(Request $request, Student $student, StudentAccountDirectory $directory): View
    {
        Gate::authorize('student-accounts.provision');

        return view('student-accounts.show', ['account' => $directory->find($student->id, $request->user()),
            'passwordConfirmed' => now()->timestamp - (int) $request->session()->get('auth.password_confirmed_at', 0) <= 900]);
    }

    public function createAccount(CreateStudentAccountRequest $request, Student $student, LinkStudentAccount $accounts): RedirectResponse
    {
        try {
            $result = $accounts->createForActor($request->user(), $student->student_number, $request->validated('login_email'),
                $request->validated('reason'), $request->boolean('identity_confirmed'));
        } catch (Throwable) {
            report(new \RuntimeException('Student account creation could not be confirmed; no credential details were recorded.'));

            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => 'Account creation could not be confirmed. Review the current status before retrying.']);
        }
        if (! $result->successful) {
            abort_if($result->code === 'forbidden', 403);

            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => $result->message])->withInput($request->only('reason'));
        }
        if (! $result->changed) {
            return to_route('student-accounts.show', $student, 303)->with('status', $result->message);
        }

        return to_route('student-accounts.show', $student, 303)->with('status',
            'Student login account created.');
    }

    public function confirm(Student $student): RedirectResponse
    {
        Gate::authorize('student-accounts.provision');

        return to_route('student-accounts.show', $student);
    }

    public function setInitialPassword(SetStudentInitialPasswordRequest $request, Student $student, StudentCredentials $credentials): RedirectResponse
    {
        try {
            $credentials->setInitialPassword($request->user(), $student->id, $request->validated());
        } catch (ValidationException $error) {
            return to_route('student-accounts.show', $student, 303)->withErrors($error->errors())->withInput($request->only('login_email', 'reason'));
        } catch (HttpException $error) {
            throw $error;
        } catch (Throwable) {
            report(new \RuntimeException('Student initial password setup failed; no credential details were recorded.'));

            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => 'Password setup could not be confirmed. Review the account status and try again.']);
        }

        return to_route('student-accounts.show', $student, 303)->with('status',
            'Student password saved. Password setup is complete; the student can now sign in.');
    }

    public function deactivate(ManageStudentAccountRequest $request, Student $student): RedirectResponse
    {
        return $this->lifecycle($request, $student, false);
    }

    public function reactivate(ManageStudentAccountRequest $request, Student $student): RedirectResponse
    {
        return $this->lifecycle($request, $student, true);
    }

    private function lifecycle(ManageStudentAccountRequest $request, Student $student, bool $reactivate): RedirectResponse
    {
        try {
            $changed = app(StudentAccountLifecycle::class)->transition($request->user(), $student->id,
                $request->validated('login_email'), $request->validated('reason'), $request->boolean('identity_confirmed'), $reactivate);
        } catch (ValidationException $error) {
            return to_route('student-accounts.show', $student, 303)->withErrors($error->errors())->withInput($request->only('login_email', 'reason'));
        } catch (HttpException $error) {
            throw $error;
        } catch (Throwable) {
            report(new \RuntimeException('Student lifecycle operation failed; no credential details were recorded.'));

            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => 'Account access could not be confirmed. Review the current status before retrying.']);
        }

        return to_route('student-accounts.show', $student, 303)->with('status', $changed
            ? ($reactivate ? 'Student access reactivated. Password setup is still required if pending; sign in again.' : 'Student access deactivated. Student and scholarship records were preserved.')
            : 'The requested access state is already set. No new transition was needed.');
    }

    public function credentialLink(ManageStudentAccountRequest $request, Student $student): RedirectResponse
    {
        $target = $student->user;
        if (! $target || Str::lower(trim($target->email)) !== $request->validated('login_email')) {
            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => 'The confirmed account association requires review.']);
        }
        try {
            $status = app(StudentCredentials::class)->issue($target, $request->user(), $request->validated('reason'), $request->boolean('identity_confirmed'), $student->id);
        } catch (HttpException $error) {
            throw $error;
        } catch (Throwable) {
            report(new \RuntimeException('Student credential request failed; no credential details were recorded.'));
            $status = 'unavailable';
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return to_route('student-accounts.show', $student, 303)->withErrors(['account' => $status === 'credential_delivery_not_approved'
                ? 'Student credential delivery is blocked until a real channel is approved and tested.'
                : 'Password instructions cannot be requested. Review the association, access state and retry limit.']);
        }

        return to_route('student-accounts.show', $student, 303)->with('status', 'Password instructions requested. Email delivery is not confirmed by this message.');
    }
}
