<?php
declare(strict_types=1);

namespace App\Exceptions;

class ResponseHaltException extends \RuntimeException
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $httpCode = 200,
    ) {
        parent::__construct('Response halted');
    }
}
