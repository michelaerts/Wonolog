<?php

/**
 * This file is part of the Wonolog package.
 *
 * (c) Inpsyde GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\Wonolog\Tests\Unit;

use Inpsyde\Wonolog\Channels;
use Inpsyde\Wonolog\Data\LogData;
use Inpsyde\Wonolog\LogActionUpdater;
use Inpsyde\Wonolog\PhpErrorController;
use Brain\Monkey\Filters;
use Inpsyde\Wonolog\Tests\UnitTestCase;
use Monolog\Logger;

class PhpErrorHandlerTest extends UnitTestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function testOnErrorNotice(): void
    {
        $updater = \Mockery::mock(LogActionUpdater::class);
        $updater->shouldNotReceive('update')->once()->andReturnUsing(
            static function (LogData $log) {
                static::assertSame(Channels::PHP_ERROR, $log->channel());
                static::assertSame(Logger::NOTICE, $log->level());
                static::assertSame('Meh!', $log->message());
                $context = $log->context();
                static::assertArrayHasKey('line', $context);
                static::assertArrayHasKey('file', $context);
                static::assertSame(__FILE__, $context['file']);
            }
        );

        $controller = PhpErrorController::new(true, $updater);
        $this->initializeErrorController($controller);

        @trigger_error('Meh!', E_USER_NOTICE);
    }

    /**
     * @test
     */
    public function testOnErrorFatal(): void
    {
        $updater = \Mockery::mock(LogActionUpdater::class);
        $updater->shouldNotReceive('update')->once()->andReturnUsing(
            static function (LogData $log) {

                static::assertSame(Channels::PHP_ERROR, $log->channel());
                static::assertSame(Logger::WARNING, $log->level());
                static::assertSame('Warning!', $log->message());
                $context = $log->context();
                static::assertArrayHasKey('line', $context);
                static::assertArrayHasKey('file', $context);
                static::assertSame(__FILE__, $context['file']);
            }
        );

        $controller = PhpErrorController::new(true, $updater);
        $this->initializeErrorController($controller);

        @trigger_error('Warning!', E_USER_WARNING);
    }

    /**
     * @test
     */
    public function testOnErrorDoNoContainGlobals(): void
    {
        $updater = \Mockery::mock(LogActionUpdater::class);
        $updater->shouldNotReceive('update')->once()->andReturnUsing(
            static function (LogData $log) {
                static::assertSame(Channels::PHP_ERROR, $log->channel());
                static::assertSame(Logger::WARNING, $log->level());
                $context = $log->context();
                static::assertArrayHasKey('line', $context);
                static::assertArrayHasKey('file', $context);
                static::assertSame(__FILE__, $context['file']);
                static::assertArrayHasKey('localVar', $context);
                static::assertSame('I am local', $context['localVar']);
                static::assertArrayNotHasKey('wp_filter', $context);
            }
        );

        $controller = PhpErrorController::new(true, $updater);
        $this->initializeErrorController($controller);
        global $wp_filter;
        $wp_filter = ['foo', 'bar'];
        $localVar = 'I am local';

        @trigger_error('Error', E_USER_WARNING);
    }

    /**
     * @test
     */
    public function testOnException(): void
    {
        $updater = \Mockery::mock(LogActionUpdater::class);
        $updater->shouldNotReceive('update')->once()->andReturnUsing(
            static function (LogData $log) {

                static::assertSame(Channels::PHP_ERROR, $log->channel());
                static::assertSame(Logger::CRITICAL, $log->level());
                static::assertSame('Exception!', $log->message());
                $context = $log->context();
                static::assertArrayHasKey('line', $context);
                static::assertArrayHasKey('trace', $context);
                static::assertArrayHasKey('file', $context);
                static::assertArrayHasKey('exception', $context);
                static::assertSame(__FILE__, $context['file']);
                static::assertSame(\RuntimeException::class, $context['exception']);
            }
        );

        $controller = PhpErrorController::new(true, $updater);
        $this->initializeErrorController($controller);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Exception!');

        try {
            throw new \RuntimeException('Exception!');
        } catch (\Exception $throwable) {
            $controller->onException($throwable);
        }
    }

    /**
     * @param PhpErrorController $controller
     * @return void
     */
    private function initializeErrorController(PhpErrorController $controller): void
    {
        Filters\expectApplied('wonolog.report-silenced-errors')->andReturn(true);

        register_shutdown_function([$controller, 'onShutdown']);
        set_error_handler([$controller, 'onError']);
        set_exception_handler([$controller, 'onException']);
    }
}
