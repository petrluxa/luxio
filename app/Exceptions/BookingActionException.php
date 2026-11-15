<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Akci s rezervací nelze provést (po lhůtě, špatný stav…). Zpráva je česky a smí se ukázat uživateli.
 */
class BookingActionException extends RuntimeException {}
