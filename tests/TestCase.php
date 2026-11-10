<?php

namespace Bitprogmbh\SweegoLaravel\Tests;

use Bitprogmbh\SweegoLaravel\SweegoServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SweegoServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'sweego');
        $app['config']->set('mail.mailers.sweego', ['transport' => 'sweego']);
        $app['config']->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Example']);
        $app['config']->set('sweego.key', 'test-key');
    }
}
