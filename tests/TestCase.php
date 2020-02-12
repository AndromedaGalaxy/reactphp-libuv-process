<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-process
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-process/blob/master/LICENSE
 * @noinspection PhpUnhandledExceptionInspection
 */

namespace Andromeda\LibuvProcess\Tests;

use PHPUnit\Framework\TestCase as PTestCase;

class TestCase extends PTestCase {
    function getCallableMock(?int $num): callable {
        $callable = $this->getMockBuilder(CallableStub::class)
            ->getMock();
        
        if($num !== null) {
            $callable
                ->expects($this->exactly($num))
                ->method('__invoke');
        }
        
        /** @var callable  $callable */
        return $callable;
    }
}