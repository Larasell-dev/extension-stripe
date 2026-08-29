<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Larasell\Stripe\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
