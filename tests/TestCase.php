<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Šablony se v testech vykreslují bez sestavených stylů a skriptů.
        $this->withoutVite();
    }
}
