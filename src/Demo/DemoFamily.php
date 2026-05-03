<?php

namespace App\Demo;

final readonly class DemoFamily
{
    public function __construct(
        public int $number,
        public DemoPerson $owner,
        public DemoPerson $spouse,
    ) {
    }
}
