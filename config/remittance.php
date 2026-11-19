<?php

return [
    // Batch 29, cash step 4: the ONE tolerance, in pesos, for a Conductor's declared closing cash against the expected cash
    // (opening + cash sales - cash refunds). A difference up to and including it is approved when the Operator confirms;
    // a larger one flags the batch. Zero by default: any difference is flagged. The difference is recorded either way.
    'tolerance_amount' => (float) env('REMITTANCE_TOLERANCE_AMOUNT', 0),
];
