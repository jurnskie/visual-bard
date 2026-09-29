<?php

namespace Jurnskie\VisualBard\Tests;

use Jurnskie\VisualBard\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
