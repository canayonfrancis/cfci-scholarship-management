<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Gate;

class ConfirmStudentAccountPassword extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        Gate::authorize('student-accounts.provision');
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && ! $request->expectsJson() && $this->shouldConfirmPassword($request, 900)) {
            // Password confirmation must return to a safe GET, not replay a POST as GET.
            $request->session()->put('url.intended', route('student-accounts.show', $request->route('student')));

            return redirect()->route('password.confirm', [], 303)
                ->withInput($request->only(['login_email', 'reason']));
        }

        return parent::handle($request, $next, 'password.confirm', 900);
    }
}
