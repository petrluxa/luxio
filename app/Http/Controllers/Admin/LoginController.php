<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Přihlášení správce a kadeřnic: přihlašovací jméno + heslo, volitelně „zapamatovat“.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(Request $request): View
    {
        // Po instalaci přijde jméno v adrese, aby ho správce nemusel psát znovu.
        $login = Str::lower(trim((string) $request->query('jmeno')));

        return view('admin.auth.login', [
            'login' => preg_match('/^[a-z0-9._@-]{1,60}$/', $login) ? $login : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $login = Str::lower(trim($credentials['login']));
        $key = 'login:'.$login.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'login' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt(['login' => $login, 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.today'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
