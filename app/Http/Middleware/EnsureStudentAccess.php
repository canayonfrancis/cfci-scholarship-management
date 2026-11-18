<?php

namespace App\Http\Middleware;

use App\Services\Accounts\StudentAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureStudentAccess
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->routeIs('logout')) {
            $user = $request->user()->fresh(['roles']);
            if (! $user || ! app(StudentAccess::class)->authenticationAllowed($user)) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson()) {
                    abort(403, 'Student login access is unavailable.');
                }

                return redirect()->route('login')->withErrors(['email' => 'Student login access is unavailable. Contact the Registrar.']);
            }
            Auth::guard('web')->setUser($user);
        }

        return $next($request);
    }
}
