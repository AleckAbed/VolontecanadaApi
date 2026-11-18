<?php

return [
    // Durée de conservation du journal d'activité, en jours (3 ans par défaut, à valider).
    'keep_days' => (int) env('AUDIT_KEEP_DAYS', 1095),
];
