<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

class ClassyTapTest extends TestCase
{
    public function testHasDesiredMethods()
    {
        $this->assertTrue(method_exists('ClassyTap', 'test'));
        $this->assertTrue(method_exists('ClassyTap', 'discover'));
        $this->assertTrue(method_exists('ClassyTap', 'tap'));
        $this->assertTrue(method_exists('ClassyTap', 'getTables'));
    }
}
