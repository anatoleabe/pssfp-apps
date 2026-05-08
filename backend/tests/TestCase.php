<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Pest\Livewire\InteractsWithLivewire;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithLivewire;
}
