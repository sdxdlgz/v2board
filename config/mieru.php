<?php

return [
    // Experimental nodes stay invisible until explicitly enabled.
    'enabled' => (bool) env('MIERU_EXPERIMENTAL_ENABLED', false),
];
