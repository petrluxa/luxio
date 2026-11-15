<?php

namespace App\Enums;

enum SegmentType: string
{
    /** Kadeřnice pracuje – blokuje kalendář. */
    case Work = 'work';

    /** Působení barvy – kadeřnice má volné ruce, neblokuje. */
    case Pause = 'pause';

    /** Úklid po službě – blokuje kalendář. */
    case Buffer = 'buffer';

    public function blocks(): bool
    {
        return $this !== self::Pause;
    }
}
