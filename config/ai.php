<?php

use Modules\Ai\Services\NullMaterialScanner;

/*
 * AI exams (Modules/Ai). Limits of uploaded materials and how the AI service is used; the service's
 * address and token live in config/services.php (`services.ai`). Per-plan caps are in config/schools.php.
 */
return [
    'queue' => env('AI_QUEUE', 'ai'),

    // Private storage for materials (never a public disk). Paths are centers/{school}/ai-materials/{uuid}.
    'disk' => env('AI_MATERIALS_DISK', 'local'),

    'materials' => [
        'max_file_mb' => (int) env('AI_MAX_FILE_MB', 25),
        'max_pdf_pages' => (int) env('AI_MAX_PDF_PAGES', 300),
        'max_xlsx_sheets' => (int) env('AI_MAX_XLSX_SHEETS', 20),
        'max_xlsx_cells' => (int) env('AI_MAX_XLSX_CELLS', 50000),
        // A DOCX/XLSX is a zip: refuse one that expands to more than this (zip bomb).
        'max_uncompressed_mb' => (int) env('AI_MAX_UNCOMPRESSED_MB', 200),
        'embed_model' => env('AI_EMBED_MODEL', 'bge-m3'),
    ],

    // Exam generation (blueprint -> draft). The AI service writes at most 10 questions per call, so
    // longer exams are generated in batches; verification takes up to 20 questions per call.
    'generation' => [
        'max_materials' => (int) env('AI_MAX_JOB_MATERIALS', 10),
        'max_questions' => (int) env('AI_MAX_EXAM_QUESTIONS', 100),
        'batch_size' => 10,
        'verify_batch_size' => 20,
        // Existing question-bank prompts sent with verification for the (informational) overlap check.
        'bank_questions' => (int) env('AI_VERIFY_BANK_QUESTIONS', 100),
        'generate_timeout' => (int) env('AI_GENERATE_TIMEOUT', 180),
    ],

    // AI units (the plan's monthly cap `ai_units_monthly`). A job reserves its estimate times this
    // margin (never more than what is left) before it starts, and can never spend past its reservation.
    'units' => [
        'reserve_margin' => (float) env('AI_UNITS_RESERVE_MARGIN', 1.2),
        'warning_percent' => 80,
    ],

    // The antivirus hook: a class implementing Modules\Ai\Contracts\MaterialScanner (ClamAV, a cloud
    // scanner...). The default accepts every file; set it before enabling uploads in production.
    'scanner' => NullMaterialScanner::class,
];
