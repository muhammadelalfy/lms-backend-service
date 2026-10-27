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
        // Per-file cap for a PDF: it is turned into light JSON by the pdf-extractor and the original is
        // removed. Word/Excel are parsed in the AI service's RAM, so they keep their own, lower cap.
        'max_file_mb' => (int) env('AI_MAX_FILE_MB', 100),
        'max_office_file_mb' => (int) env('AI_MAX_OFFICE_FILE_MB', 25),
        // false (default): a PDF is deleted as soon as its text is stored. true keeps it (and its size counts).
        'keep_originals' => (bool) env('AI_KEEP_ORIGINALS', false),
        // Chunked upload: the browser slices a file into pieces of this size (nginx must allow one piece).
        'chunk_mb' => (int) env('AI_UPLOAD_CHUNK_MB', 5),
        'upload_ttl_hours' => (int) env('AI_UPLOAD_TTL_HOURS', 24),
        // Before extraction the light size is unknown: the plan's storage check uses this share of a PDF.
        'light_estimate_ratio' => (float) env('AI_LIGHT_ESTIMATE_RATIO', 0.1),
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

    // Grading assistance (S71): one AI suggestion per written answer, the teacher always decides.
    'grading' => [
        'timeout' => (int) env('AI_GRADING_TIMEOUT', 60),
        'max_answer_chars' => 8000,
        // Below this confidence (or with a suspected injection) the item is marked for attention and
        // never taken by the bulk accept.
        'attention_below' => (float) env('AI_GRADING_ATTENTION_BELOW', 0.6),
        // The bulk accept's default threshold (the teacher confirms the count first).
        'bulk_accept_min_confidence' => 0.85,
        'bulk_max' => 200,
        // At 100% of the monthly cap no new answer is sent to the AI; answers already queued still
        // run until the month's spending reaches the cap plus this share of it (the hard stop).
        'hard_stop_percent' => (int) env('AI_GRADING_HARD_STOP_PERCENT', 10),
    ],

    // The antivirus hook: a class implementing Modules\Ai\Contracts\MaterialScanner (ClamAV, a cloud
    // scanner...). The default accepts every file; set it before enabling uploads in production.
    'scanner' => NullMaterialScanner::class,
];
