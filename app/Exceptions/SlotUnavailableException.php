<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Vybraný termín už není volný (typicky ho mezitím zabral někdo jiný).
 */
class SlotUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Tento termín už bohužel není volný. Vyberte prosím jiný čas.')
    {
        parent::__construct($message);
    }
}
