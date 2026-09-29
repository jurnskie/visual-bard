<?php

namespace Jurnskie\VisualBard;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/addon.js'],
        'publicDirectory' => 'public/vendor/visual-bard',
    ];
}
