<?php

return [
    // The school has confirmed this year, but not an automatic calendar rollover date.
    // Future years can be selected with the Artisan --academic-year option.
    'academic_year' => env('SMS_SAMPLE_ACADEMIC_YEAR'),
    'confirmed_academic_year' => '2026-2027',
    'password' => env('SMS_SAMPLE_PASSWORD', 'CfciSample2026!'),
    'program_defaults' => ['TES' => '7500.00', 'TDP' => '13500.00', 'COSCHO' => '45000.00'],
    // Enabled only in memory by the explicit, isolated demo command.
    'synthetic_demo' => false,
];
