<?php

return [
    'max_upload_kb' => (int) env('SCHOLAR_IMPORT_MAX_UPLOAD_KB', 5120),
    'max_rows' => (int) env('SCHOLAR_IMPORT_MAX_ROWS', 1000),
    'preview_minutes' => (int) env('SCHOLAR_IMPORT_PREVIEW_MINUTES', 30),
    'max_scanned_rows' => 10000,
    'max_uncompressed_bytes' => 32 * 1024 * 1024,
    'max_xml_bytes' => 8 * 1024 * 1024,
];
