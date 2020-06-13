<?php
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Tests\MutexRun;

use App\MutexRun\CallableOnce;
use App\Tests\TestCase;
use Exception;
use React\Promise\Deferred;
use function React\Promise\reject;
use function React\Promise\resolve;

class CallableOnceTest extends TestCase
{

    public function testInvokeTwiceWillRunCallbackOnce()
    {
        $callable = new CallableOnce($this->expectCallableOnce());
        $callable->__invoke();
        $callable->__invoke();
    }

    public function testReturnsSameResultOnSubsequentCalls()
    {
        $callable = new CallableOnce($this->expectCallableOnce());
        $this->assertSame($callable->__invoke(), $callable->__invoke());
    }

    public function testInvokeParametersArePassedToCallback()
    {
        $callable = new CallableOnce($this->expectCallableOnceWith('param1', 'param2'));
        $callable->__invoke('param1', 'param2');
    }

    public function testPromiseResolvesWithCallbackReturnValue()
    {
        $value = 'value';
        $callable = new CallableOnce(function () use ($value) {
            return $value;
        });
        $callable->__invoke()->then($this->expectCallableOnceWith($value), $this->expectCallableNever());
    }

    public function testPromiseResolvesWithSameValueAsPromiseReturnedByCallback()
    {
        $value = 'value';
        $callable = new CallableOnce(function () use ($value) {
            return resolve($value);
        });
        $callable->__invoke()->then($this->expectCallableOnceWith($value), $this->expectCallableNever());
    }

    public function testCallbackRunsOnEveryInvokeWhenAllowAfterResolveSet()
    {
        $callable = new CallableOnce($this->expectCallableExactly(3), true);
        $callable->__invoke();
        $callable->__invoke();
        $callable->__invoke();
    }

    public function testCallbackRunsOnEveryInvokeIfItReturnsRejectedPromiseWhenAllowAfterResolveSet()
    {
        $callableMock = $this->expectCallableExactly(3);
        $callable = new CallableOnce(function () use ($callableMock) {
            $callableMock->__invoke();
            return reject();
        }, true);
        $callable->__invoke();
        $callable->__invoke();
        $callable->__invoke();
    }

    public function testPromisesAreResolvedWithValuesReturnedByCallbackWhenAllowAfterResolveSet()
    {
        $value1 = 'value1';
        $value2 = 'value2';
        $callable = new CallableOnce(function () use ($value1, $value2) {
            static $called = 0;
            return $called++ ? $value2 : $value1;
        }, true);
        $callable->__invoke()->then($this->expectCallableOnceWith($value1));
        $callable->__invoke()->then($this->expectCallableOnceWith($value2));
    }

    public function testCallbackIsCalledOnlyOnceBeforePromiseResolvedWhenAllowAfterResolveSet()
    {
        $callbackMock = $this->expectCallableOnce();
        $callable = new CallableOnce(function () use ($callbackMock) {
            $callbackMock->__invoke();
            return (new Deferred())->promise();
        }, true);
        $callable->__invoke();
        $callable->__invoke();
    }

    public function testPromiseRejectsWithExceptionThrownByCallback()
    {
        $exception = new Exception();
        $callable = new CallableOnce(function () use ($exception) {
            throw $exception;
        });
        $callable->__invoke()->then($this->expectCallableNever(), $this->expectCallableOnceWith($exception));
    }

}
