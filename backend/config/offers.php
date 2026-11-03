<?php

return [
    // How long a store user waits before setting a new price for the same pack at the same store.
    // Brochure entry, manual entry and CSV imports all count. Staff with "Skip price wait" are exempt.
    'price_wait_hours' => (int) env('OFFER_PRICE_WAIT_HOURS', 6),
];
