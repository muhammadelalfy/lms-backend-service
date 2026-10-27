<?php

namespace Database\Seeders;

use Modules\Attendance\Models\AttendanceRecord;
use Modules\Groups\Models\ClassGroup;
use Modules\Notifications\Notifications\SchoolUpdateNotification;
use Modules\Exams\Models\ExamDepartment;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamSession;
use Modules\Exams\Models\ExamSessionAnswer;
use Modules\Exams\Models\ExamSessionEvent;
use Modules\Exams\Models\ExamTemplate;
use Modules\Payments\Models\Payment;
use Modules\Exams\Models\QuestionBankQuestion;
use Modules\PluginStore\Models\PluginProduct;
use Modules\PluginStore\Models\PluginPurchase;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Modules\Auth\Models\User;
use Modules\Learning\Models\Worksheet;
use Modules\Learning\Models\WorksheetAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ArabicDemoSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@local.test';
    private const ADMIN_PASSWORD = 'AdminLocal!2026';
    private const TEACHER_EMAIL = 'teacher@local.test';
    private const TEACHER_PASSWORD = 'TeacherLocal!2026';
    private const PARENT_PASSWORD = 'ParentLocal!2026';
    private const STUDENT_PASSWORD = 'StudentLocal!2026';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ArabicDemoSeeder is restricted to local and testing environments.');
        }

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير مُدرّسى', 'admin', self::ADMIN_PASSWORD);
        $teacher = $this->user(self::TEACHER_EMAIL, 'أستاذ المادة', 'teacher', self::TEACHER_PASSWORD);
        $students = $this->seedStudents();
        // Weekly timetables (ISO weekdays: 6 = Saturday … 4 = Thursday).
        foreach ([
            ['name' => 'المجموعة الأولى', 'start_time' => '15:00', 'end_time' => '16:30', 'days' => [6, 1, 3]],
            ['name' => 'المجموعة الثانية', 'start_time' => '17:00', 'end_time' => '18:30', 'days' => [7, 2, 4]],
            ['name' => 'المجموعة الثالثة', 'start_time' => '19:00', 'end_time' => '20:30', 'days' => [6, 1, 3]],
        ] as $group) {
            ClassGroup::updateOrCreate(['name' => $group['name']], [...$group, 'late_after_minutes' => 10]);
        }
        foreach ([
            ['group' => 'المجموعة الأولى', 'title' => 'حل تمارين المعادلات صفحة ٤٢', 'details' => 'من التمرين ١ إلى ٨.', 'due_on' => now()->toDateString()],
            ['group' => 'المجموعة الثانية', 'title' => 'مراجعة النسبة والتناسب', 'details' => null, 'due_on' => now()->toDateString()],
        ] as $duty) {
            \Modules\Learning\Models\Duty::updateOrCreate(['group' => $duty['group'], 'title' => $duty['title']], [...$duty, 'created_by' => $teacher->id]);
        }
        $this->seedExams($teacher, $students);
        $this->seedQuestionBank($teacher);
        $this->seedPlugins($admin);

        $worksheets = collect([
            ['title' => 'مراجعة المعادلات الخطية', 'grade' => 'الأول الإعدادي'],
            ['title' => 'تدريب على النسب والتناسب', 'grade' => 'الثاني الإعدادي'],
            ['title' => 'ورقة الهندسة والمثلثات', 'grade' => 'الثالث الإعدادي'],
        ])->map(fn (array $attributes) => $this->worksheet($attributes, $teacher))->all();

        foreach ($students as $index => $student) {
            $parent = $this->user("parent{$index}@local.test", "ولي أمر {$student->name}", 'parent', self::PARENT_PASSWORD);
            $learner = $this->user("student{$index}@local.test", $student->name, 'student', self::STUDENT_PASSWORD);
            StudentAccount::updateOrCreate(['user_id' => $parent->id], ['student_id' => $student->id, 'relationship' => 'parent']);
            StudentAccount::updateOrCreate(['user_id' => $learner->id], ['student_id' => $student->id, 'relationship' => 'student']);
            foreach ([$parent, $learner] as $account) {
                if (! $account->notifications()->exists()) {
                    $account->notify(new SchoolUpdateNotification('مرحباً بك في مُدرّسى', 'تابع الحضور والواجبات والنتائج والمدفوعات من مكان واحد.', 'welcome'));
                }
            }

            $worksheet = $worksheets[$index % count($worksheets)];
            WorksheetAssignment::updateOrCreate(
                ['worksheet_id' => $worksheet->id, 'student_id' => $student->id],
                ['status' => $index % 3 === 0 ? 'graded' : 'submitted', 'assigned_at' => now()->subDays(7), 'submitted_at' => now()->subDays(2), 'score' => 14 + ($index % 7), 'max_score' => 20, 'feedback' => 'عمل جيد، راجع خطوات الحل بهدوء.'],
            );

            for ($day = 1; $day <= 5; $day++) {
                $date = now()->subDays($day);
                AttendanceRecord::updateOrCreate(
                    ['student_id' => $student->id, 'attendance_date' => $date->toDateString()],
                    ['date_at' => $date->setTime(8, 15), 'status' => $day === 3 && $index % 3 === 0 ? 'late' : 'present', 'note' => null, 'recorded_by' => $teacher->id],
                );
            }

            ExamResult::updateOrCreate(
                ['student_id' => $student->id, 'title' => 'اختبار منتصف الفصل'],
                ['score' => 12 + ($index % 9), 'max_score' => 20, 'taken_at' => now()->subDays(10), 'recorded_by' => $teacher->id, 'status' => 'published', 'published_at' => now()->subDays(9), 'published_by' => $teacher->id],
            );

            Payment::updateOrCreate(
                ['student_id' => $student->id, 'due_at' => now()->startOfMonth()],
                ['amount' => 450, 'status' => $index % 4 === 0 ? 'pending' : 'paid', 'paid_at' => $index % 4 === 0 ? null : now()->subDays(4), 'note' => 'اشتراك شهر تجريبي', 'recorded_by' => $admin->id],
            );
        }

        $this->command?->info('Arabic LMS demo data is ready. QR codes were generated for all demo students.');
        $this->command?->info('Admin: '.self::ADMIN_EMAIL.' / '.self::ADMIN_PASSWORD);
        $this->command?->info('Teacher: '.self::TEACHER_EMAIL.' / '.self::TEACHER_PASSWORD);
        $this->command?->info('Parent password: '.self::PARENT_PASSWORD.' · Student password: '.self::STUDENT_PASSWORD);
    }

    private function seedQuestionBank(User $teacher): void
    {
        $questions = [
            ['title' => 'تبسيط تعبير جبري', 'type' => 'math', 'grade' => 'الأول الإعدادي', 'prompt_html' => '<p>بسّط التعبير الجبري التالي.</p>', 'options' => ['notation' => 'س^2 + 2س + 1'], 'correct_answer' => '(س + 1)^2', 'points' => 3, 'tags' => 'جبر،تبسيط'],
            ['title' => 'مساحة مستطيل', 'type' => 'geometry', 'grade' => 'الأول الإعدادي', 'prompt_html' => '<p>احسب مساحة المستطيل الموضح في الرسم.</p>', 'options' => ['shape' => 'rectangle', 'dimensions' => ['width' => '6 سم', 'height' => '4 سم']], 'correct_answer' => '24 سم²', 'points' => 4, 'tags' => 'هندسة،مساحة'],
            ['title' => 'قيمة تعبير', 'type' => 'mcq', 'grade' => 'الثاني الإعدادي', 'prompt_html' => '<p>ما قيمة ٣ × ٤؟</p>', 'options' => ['١٠', '١٢', '١٤'], 'correct_answer' => '١٢', 'points' => 1, 'tags' => 'حساب'],
        ];
        foreach ($questions as $question) {
            QuestionBankQuestion::updateOrCreate(['title' => $question['title']], [...$question, 'created_by' => $teacher->id, 'is_active' => true]);
        }
    }

    private function seedExams(User $teacher, array $students): void
    {
        $departments = collect([
            ['name' => 'الجبر والمعادلات', 'slug' => 'algebra-equations', 'description' => 'اختبارات الجبر والمعادلات الخطية.'],
            ['name' => 'الهندسة', 'slug' => 'geometry', 'description' => 'اختبارات الهندسة والأشكال والقياس.'],
            ['name' => 'الإحصاء', 'slug' => 'statistics', 'description' => 'اختبارات الإحصاء وتحليل البيانات.'],
        ])->mapWithKeys(fn (array $attributes) => [$attributes['slug'] => ExamDepartment::updateOrCreate(['slug' => $attributes['slug']], [...$attributes, 'is_active' => true])]);

        $templates = [
            ['title' => 'اختبار الجبر الأول', 'slug' => 'algebra-first-test', 'department' => 'algebra-equations', 'grade' => 'الأول الإعدادي', 'status' => 'published'],
            ['title' => 'مراجعة الهندسة', 'slug' => 'geometry-review', 'department' => 'geometry', 'grade' => 'الثاني الإعدادي', 'status' => 'published'],
            ['title' => 'مسودة اختبار الإحصاء', 'slug' => 'statistics-draft', 'department' => 'statistics', 'grade' => 'الثالث الإعدادي', 'status' => 'draft'],
        ];

        foreach ($templates as $templateData) {
            $template = ExamTemplate::updateOrCreate(
                ['title' => $templateData['title']],
                [
                    'department_id' => $departments[$templateData['department']]->id,
                    'created_by' => $teacher->id,
                    'grade' => $templateData['grade'],
                    'duration_minutes' => 45,
                    'instructions' => 'اقرأ الأسئلة جيداً، واكتب خطوات الحل بوضوح قبل التسليم.',
                    'watermark_text' => 'مُدرّسى · '.$templateData['grade'],
                    'watermark_opacity' => 12,
                    'status' => $templateData['status'],
                ],
            );

            $questions = [
                ['type' => 'mcq', 'prompt_html' => '<p>إذا كان س = ٣، فما قيمة ٢س + ١؟</p>', 'options' => ['٥', '٦', '٧', '٨'], 'correct_answer' => '٧', 'points' => 2, 'sort_order' => 0],
                ['type' => 'true_false', 'prompt_html' => '<p>مجموع زوايا المثلث يساوي ١٨٠ درجة.</p>', 'options' => ['صح', 'خطأ'], 'correct_answer' => 'صح', 'points' => 1, 'sort_order' => 1],
                ['type' => 'math', 'prompt_html' => '<p>حل المعادلة: س + ٤ = ٩.</p>', 'options' => null, 'correct_answer' => '٥', 'points' => 3, 'sort_order' => 2],
                ['type' => 'geometry', 'prompt_html' => '<p>احسب مساحة المستطيل الموضح.</p>', 'options' => ['shape' => 'rectangle', 'dimensions' => ['width' => '٦ سم', 'height' => '٤ سم'], 'labels' => ['title' => 'مستطيل بأبعاد معلومة']], 'correct_answer' => '٢٤ سم²', 'points' => 4, 'sort_order' => 3],
            ];

            foreach ($questions as $questionData) {
                ExamQuestion::updateOrCreate(
                    ['template_id' => $template->id, 'sort_order' => $questionData['sort_order']],
                    [...$questionData, 'template_id' => $template->id],
                );
            }

            if ($template->status !== 'published') {
                continue;
            }

            foreach (array_slice($students, 0, 2) as $index => $student) {
                $session = ExamSession::updateOrCreate(
                    ['template_id' => $template->id, 'student_id' => $student->id],
                    [
                        'started_at' => now()->subMinutes(18 + $index),
                        'submitted_at' => $index === 1 ? now()->subMinutes(3) : null,
                        'status' => $index === 1 ? 'submitted' : 'active',
                        'camera_required' => true,
                        'fullscreen_required' => true,
                        'focus_loss_count' => $index,
                        'last_event_at' => now()->subMinute(),
                    ],
                );

                foreach ($template->questions as $question) {
                    ExamSessionAnswer::updateOrCreate(
                        ['session_id' => $session->id, 'question_id' => $question->id],
                        ['answer' => $question->correct_answer, 'answered_at' => now()->subMinutes(4)],
                    );
                }

                ExamSessionEvent::updateOrCreate(
                    ['session_id' => $session->id, 'type' => 'camera_granted'],
                    ['metadata' => ['source' => 'seeded-demo', 'student' => $student->name], 'occurred_at' => $session->started_at],
                );

                if ($index === 1) {
                    ExamSessionEvent::updateOrCreate(
                        ['session_id' => $session->id, 'type' => 'submitted'],
                        ['metadata' => ['source' => 'seeded-demo'], 'occurred_at' => $session->submitted_at],
                    );
                }
            }
        }
    }

    private function seedPlugins(User $admin): void
    {
        $plugin = PluginProduct::updateOrCreate(
            ['slug' => 'attendance-insights'],
            [
                'name' => 'تحليلات الحضور',
                'description' => 'وحدة جاهزة لعرض مؤشرات الحضور اليومية داخل لوحة الإدارة.',
                'version' => '1.0.0',
                'module_name' => 'AttendanceInsights',
                'artifact_path' => 'plugins/artifacts/attendance-insights.zip',
                'artifact_sha256' => 'c91600cd5ec365daee974159f041af14b38d734bdd9430b60542e52ae485d21e',
                'price' => 0,
                'is_active' => true,
                'metadata' => ['category' => 'reports', 'language' => 'ar'],
            ],
        );

        PluginPurchase::updateOrCreate(
            ['user_id' => $admin->id, 'plugin_product_id' => $plugin->id],
            ['status' => 'completed', 'purchased_at' => now()],
        );
    }

    private function seedStudents(): array
    {
        $students = [
            ['name' => 'أحمد محمد علي', 'phone' => '01010000001', 'parent_phone' => '01110000001', 'grade' => 'الأول الإعدادي', 'group' => 'المجموعة الأولى'],
            ['name' => 'سارة محمود حسن', 'phone' => '01010000002', 'parent_phone' => '01110000002', 'grade' => 'الأول الإعدادي', 'group' => 'المجموعة الأولى'],
            ['name' => 'يوسف خالد إبراهيم', 'phone' => '01010000003', 'parent_phone' => '01110000003', 'grade' => 'الثاني الإعدادي', 'group' => 'المجموعة الثانية'],
            ['name' => 'ملك أحمد السيد', 'phone' => '01010000004', 'parent_phone' => '01110000004', 'grade' => 'الثاني الإعدادي', 'group' => 'المجموعة الثانية'],
            ['name' => 'عمر سامح عبد الله', 'phone' => '01010000005', 'parent_phone' => '01110000005', 'grade' => 'الثالث الإعدادي', 'group' => 'المجموعة الثالثة'],
            ['name' => 'نورهان محمد سالم', 'phone' => '01010000006', 'parent_phone' => '01110000006', 'grade' => 'الثالث الإعدادي', 'group' => 'المجموعة الثالثة'],
        ];

        return collect($students)->map(function (array $attributes): Student {
            $student = Student::where('phone', $attributes['phone'])->first() ?? Student::factory()->create($attributes);
            $student->ensureQrToken();
            return $student;
        })->all();
    }

    private function user(string $email, string $name, string $role, string $password): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => $role, 'password' => Hash::make($password)],
        );
    }

    private function worksheet(array $attributes, User $teacher): Worksheet
    {
        return Worksheet::where('title', $attributes['title'])->first() ?? Worksheet::factory()->create([
            ...$attributes,
            'subject' => 'الرياضيات',
            'instructions' => 'حل الأسئلة بخطوات واضحة، ثم راجع إجاباتك قبل التسليم.',
            'due_at' => now()->addDays(7),
            'status' => 'published',
            'created_by' => $teacher->id,
        ]);
    }
}
