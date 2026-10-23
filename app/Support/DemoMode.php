<?php

namespace App\Support;

final class DemoMode
{
    public static function available(): bool
    {
        return app()->environment(['local', 'testing']) || self::public();
    }

    public static function public(): bool
    {
        return app()->environment('production')
            && config('policyflow.public_demo_enabled')
            && ! config('app.debug');
    }
}
