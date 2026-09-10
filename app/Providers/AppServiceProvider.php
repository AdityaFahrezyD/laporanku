<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Observers\TransactionCacheObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        foreach ([Income::class, Expense::class, Transfer::class, Wallet::class, Category::class, Attachment::class] as $model) {
            $model::observe(TransactionCacheObserver::class);
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(max(1, config('traffic.login_ip_per_minute')))->by('ip:'.$request->ip()),
            Limit::perMinute(max(1, config('traffic.login_per_minute')))->by('account:'.hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())),
        ]);

        foreach (['reads', 'writes'] as $kind) {
            RateLimiter::for('api-'.$kind, function (Request $request) use ($kind) {
                $user = $request->user('sanctum');

                return Limit::perMinute(max(1, config("traffic.{$kind}_per_minute")))
                    ->by($user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
            });
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
