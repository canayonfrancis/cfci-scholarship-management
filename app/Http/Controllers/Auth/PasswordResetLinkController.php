<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\StudentCredentials;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\StudentAccess;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $target = User::where('email', $request->email)->first();
        try {
            if ($target && app(StudentAccess::class)->usesStudentCredentials($target)) {
                app(StudentCredentials::class)->issue($target);
            } elseif (app(StudentCredentialDelivery::class)->available()) {
                Password::sendResetLink($request->only('email'));
            }
        } catch (\Throwable) {
            report(new \RuntimeException('Credential delivery request failed; no credential details were recorded.'));
        }

        return back()->with('status', 'If this account is eligible and credential delivery is available, password instructions will be sent.');
    }
}
