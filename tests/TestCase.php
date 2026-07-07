<?php

namespace Ssntpl\Neev\Tests;

use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Ssntpl\Neev\NeevServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            NeevServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');

        // Most tests exercise the Blade web flows; simulate an app that
        // ejected the Blade starter kit. Headless-specific tests override
        // this back to null in their own defineEnvironment().
        $app['config']->set('neev.ui', 'blade');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve kit views straight from the stubs, as if they had been
        // ejected to resources/views/vendor/neev.
        $stubViews = dirname(__DIR__) . '/stubs/blade/views';
        $this->app['view']->prependNamespace('neev', $stubViews);
        Blade::anonymousComponentPath($stubViews . '/components', 'neev-component');
        Blade::anonymousComponentPath($stubViews . '/layouts', 'neev-layout');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
