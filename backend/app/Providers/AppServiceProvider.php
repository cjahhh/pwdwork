<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Services\PasswordRecoveryMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by('api:'.($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip())));
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by('registration:'.$request->ip()));
        RateLimiter::for('login-account', function (Request $request) {
            $email = $request->input('email');
            // No account lookup: known and unknown addresses share the same policy.
            // Fold variants also accepted by MySQL's case/accent-insensitive collation.
            $account = is_string($email) ? Str::transliterate(Str::lower(trim($email))) : '';

            return Limit::perMinute(10)->by('login-account:'.hash('sha256', $account));
        });
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(5)->by('reports:'.$request->user()->id));
        RateLimiter::for('password-email', fn (Request $request) => Limit::perMinute(5)->by('password-email:'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('password-reset:'.$request->ip()));
        RateLimiter::for('verification-resend', fn (Request $request) => [
            Limit::perMinute(6)->by('verification-resend-minute:'.$request->ip()),
            Limit::perHour(30)->by('verification-resend-hour:'.$request->ip()),
        ]);
        RateLimiter::for('verification-confirm', fn (Request $request) => Limit::perMinute(10)->by('verification-confirm:'.$request->user()->id));
        Mail::extend('brevo', fn (array $config = []) => new BrevoTransport((string) config('services.brevo.key')));
        ResetPassword::createUrlUsing(fn ($user, $token) => app(PasswordRecoveryMail::class)->url($user, $token));
        ResetPassword::toMailUsing(fn ($user, $token) => app(PasswordRecoveryMail::class)->message($user, $token));
    }
}
