<?php

namespace App\Providers;

use App\Support\Database\RlsContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            RlsContext::enableBypass();
        }

        $this->guardStepUpBypassIsDevOnly();
    }

    /**
     * Fail-fast safeguard for STEPUP-01 (see SECURITY_AUDIT.md): this
     * project has no CI/CD pipeline to run an automated pre-deploy check,
     * so this runtime guard is the substitute. If DISABLE_STEP_UP is ever
     * true outside a local environment — e.g. an env value copied into a
     * staging/production config by mistake — refuse to boot rather than
     * silently allow step-up auth to be bypassed.
     */
    private function guardStepUpBypassIsDevOnly(): void
    {
        if (config('app.disable_step_up') && ! $this->app->environment('local')) {
            throw new \RuntimeException(
                'DISABLE_STEP_UP is enabled outside a local environment (APP_ENV='
                    .config('app.env').'). Refusing to boot — this flag must never '
                    .'be true in staging/production. See SECURITY_AUDIT.md (STEPUP-01).'
            );
        }
    }
}
