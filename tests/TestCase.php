<?php

namespace AmjadIqbal\PennantExperiments\Tests;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use AmjadIqbal\PennantExperiments\PennantExperimentsServiceProvider;
use AmjadIqbal\PennantExperiments\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Laravel\Pennant\PennantServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PennantServiceProvider::class, PennantExperimentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('pennant.default', 'database');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->default('x');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/pennant/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager()->flush();
        // A browser, so visitors are not treated as bots.
        $this->app['request']->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh) Test');
    }

    protected function manager(): ExperimentManager
    {
        return $this->app->make(ExperimentManager::class);
    }

    protected function user(int $n = 1): User
    {
        return User::query()->create(['name' => "User {$n}", 'email' => "user{$n}@example.com"]);
    }

    /** Simulate a fresh request: Pennant's in-memory cache and the manager's memos are dropped. */
    protected function nextRequest(): void
    {
        Feature::flushCache();
        $this->manager()->flush();
    }
}
