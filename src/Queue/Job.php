<?php
declare(strict_types=1);

namespace App\Queue;

interface Job
{
    public function handle(): void;

    public function toArray(): array;

    public static function fromArray(array $data): static;
}
