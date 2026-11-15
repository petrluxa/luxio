<?php

return [

    /*
    | Fronta (push a e-maily) vyřizovaná plánovačem – pro hosting bez trvale
    | běžícího procesu. Kde běží `queue:work` pod supervizorem, nastavte false.
    */
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', true),

];
