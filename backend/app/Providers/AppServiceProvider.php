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
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(5)->by('reports:'.$request->user()->id));
        RateLimiter::for('password-email', fn (Request $request) => Limit::perMinute(5)->by('password-email:'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('password-reset:'.$request->ip()));
        Mail::extend('brevo', fn (array $config = []) => new BrevoTransport((string) config('services.brevo.key')));
        ResetPassword::createUrlUsing(fn ($user, $token) => app(PasswordRecoveryMail::class)->url($user, $token));
        ResetPassword::toMailUsing(fn ($user, $token) => app(PasswordRecoveryMail::class)->message($user, $token));
    }
}
