<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->travelTo(new DateTimeImmutable('2026-10-13T09:00:00+00:00'));
    })
    ->afterEach(function (): void {
        $this->travelBack();
    })
    ->in('Feature');
