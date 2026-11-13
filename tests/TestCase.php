<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use PDO;

/**
 * Toda prueba corre contra una SQLite en memoria que nace vacía con el
 * proceso y muere con él. Aquí no se usa RefreshDatabase ni migrate:fresh:
 * ese trait vacía la base a la que apunte la configuración, y con el caché
 * de configuración activo esa base es la real. Si la conexión activa no es
 * la de memoria, la suite se detiene antes de tocar nada.
 */
abstract class TestCase extends BaseTestCase
{
    /** Misma base en memoria para todo el proceso; cada prueba se revierte. */
    private static ?PDO $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase();
    }

    private function useInMemoryDatabase(): void
    {
        $name = config('database.default');
        $driver = config("database.connections.{$name}.driver");
        $file = config("database.connections.{$name}.database");

        if ($driver !== 'sqlite' || $file !== ':memory:') {
            self::fail(sprintf(
                'Las pruebas solo corren contra SQLite en memoria y la conexión activa es %s (%s). '
                .'Revisa phpunit.xml y ejecuta `php artisan config:clear`.',
                $driver,
                $file,
            ));
        }

        $connection = $this->app->make('db')->connection($name);

        if (self::$database === null) {
            Artisan::call('migrate');
            $this->app[Kernel::class]->setArtisan(null);
            self::$database = $connection->getPdo();
        } else {
            $connection->setPdo(self::$database);
        }

        $this->rollBackAfterTest($connection, $name);
    }

    private function rollBackAfterTest(Connection $connection, string $name): void
    {
        $this->app->instance('db.transactions', $manager = new DatabaseTransactionsManager([$name]));
        $connection->setTransactionManager($manager);

        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        $connection->beginTransaction();
        $connection->setEventDispatcher($dispatcher);

        $this->beforeApplicationDestroyed(function () use ($connection): void {
            $connection->unsetEventDispatcher();
            $connection->rollBack();
            $connection->disconnect();
        });
    }
}
