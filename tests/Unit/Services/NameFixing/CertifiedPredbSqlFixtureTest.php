<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Release;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__.'/CertifiedPredbSqlFixture.php';

final class CertifiedPredbSqlFixtureTest extends TestCase
{
    public static function dispatcherStates(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('dispatcherStates')]
    public function test_fixture_restores_the_callers_model_event_dispatcher(bool $present): void
    {
        $original = Release::getEventDispatcher();
        $expected = $present ? new Dispatcher : null;
        if ($expected === null) {
            Release::unsetEventDispatcher();
        } else {
            Release::setEventDispatcher($expected);
        }
        $fixture = new class
        {
            use CertifiedPredbSqlFixture;

            public function begin(): void
            {
                $this->setUpCertifiedSqlFixture();
            }

            public function finish(): void
            {
                $this->tearDownCertifiedSqlFixture();
            }
        };
        try {
            $fixture->begin();
            $fixture->finish();
            self::assertSame($expected, Release::getEventDispatcher());
        } finally {
            if ($original === null) {
                Release::unsetEventDispatcher();
            } else {
                Release::setEventDispatcher($original);
            }
        }
    }

    #[DataProvider('dispatcherStates')]
    public function test_throwing_rollback_callback_restores_all_caller_globals(bool $present): void
    {
        $originalContainer = Container::getInstance();
        $originalFacade = Facade::getFacadeApplication();
        $originalResolver = Release::getConnectionResolver();
        $originalDispatcher = Release::getEventDispatcher();
        $resolvedProperty = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $originalResolved = $resolvedProperty->getValue();
        $callerContainer = new Container;
        $callerResolver = $this->createStub(ConnectionResolverInterface::class);
        $callerDispatcher = $present ? new Dispatcher : null;
        Container::setInstance($callerContainer);
        Facade::setFacadeApplication($callerContainer);
        Facade::clearResolvedInstances();
        Release::setConnectionResolver($callerResolver);
        if ($callerDispatcher === null) {
            Release::unsetEventDispatcher();
        } else {
            Release::setEventDispatcher($callerDispatcher);
        }
        $fixture = new class
        {
            use CertifiedPredbSqlFixture;

            public function begin(): void
            {
                $this->setUpCertifiedSqlFixture();
            }

            public function finish(): void
            {
                $this->tearDownCertifiedSqlFixture();
            }
        };
        $connection = null;
        try {
            $fixture->begin();
            $connection = DB::connection();
            $failure = new RuntimeException('Synthetic owned rollback callback failure.');
            $poisonContainer = new Container;
            $poisonResolver = $this->createStub(ConnectionResolverInterface::class);
            $callbackCalls = 0;
            $connection->beginTransaction();
            $connection->afterRollBack(static function () use ($failure, $poisonContainer, $poisonResolver, &$callbackCalls): void {
                $callbackCalls++;
                Container::setInstance($poisonContainer);
                Facade::setFacadeApplication($poisonContainer);
                Release::setConnectionResolver($poisonResolver);
                Release::setEventDispatcher(new Dispatcher);
                throw $failure;
            });
            $observed = null;
            try {
                $fixture->finish();
            } catch (RuntimeException $exception) {
                $observed = $exception;
            }
            self::assertSame($failure, $observed);
            self::assertSame(1, $callbackCalls, 'Cleanup must not retry an irreversible callback.');
            self::assertSame([
                'container' => true, 'facade_application' => true, 'model_resolver' => true, 'model_dispatcher' => true,
            ], [
                'container' => Container::getInstance() === $callerContainer,
                'facade_application' => Facade::getFacadeApplication() === $callerContainer,
                'model_resolver' => Release::getConnectionResolver() === $callerResolver,
                'model_dispatcher' => Release::getEventDispatcher() === $callerDispatcher,
            ]);
        } finally {
            if ($connection !== null) {
                // Discard the owned failed callback manager; never replay its callback.
                $pdo = $connection->getRawPdo();
                if ($pdo instanceof \PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $connection->setTransactionManager(new DatabaseTransactionsManager);
                $connection->disconnect();
            }
            if ($originalResolver === null) {
                Release::unsetConnectionResolver();
            } else {
                Release::setConnectionResolver($originalResolver);
            }
            if ($originalDispatcher === null) {
                Release::unsetEventDispatcher();
            } else {
                Release::setEventDispatcher($originalDispatcher);
            }
            Facade::setFacadeApplication($originalFacade);
            $resolvedProperty->setValue(null, $originalResolved);
            Container::setInstance($originalContainer);
        }
    }
}
