<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\StudentCredentials;
use App\Http\Controllers\Controller;
use App\Services\Accounts\StudentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        if (app(StudentAccess::class)->usesStudentCredentials($request->user())) {
            try {
                app(StudentCredentials::class)->changeOwnPassword($request->user(), $validated['current_password'], $validated['password']);
            } catch (ValidationException $error) {
                throw $error;
            } catch (\Throwable) {
                report(new \RuntimeException('Student password change failed; no credential details were recorded.'));

                return back()->withErrors(['current_password' => 'Password change could not be confirmed. Review your account before retrying.'], 'updatePassword');
            }
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Password changed. Sign in again with your new password.');
        }

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'password-updated');
    }
}
