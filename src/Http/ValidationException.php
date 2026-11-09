<?php
declare(strict_types=1);

namespace App\Http;

final class ValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }
}
