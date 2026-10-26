<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Tenancy\Models\PlanRequest;
use Modules\Tenancy\Notifications\PlanRequestReceived;
use Tests\TestCase;

/** POST /api/plan-requests: a custom-plan lead from the landing, and the operator's inbox of them. */
class PlanRequestTest extends TestCase
{
    private const URL = 'http://localhost/api/plan-requests';

    private const INBOX = 'http://localhost/api/central/plan-requests';

    protected function setUp(): void
    {
        parent::setUp();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        URL::forceRootUrl('http://localhost');
        config(['landing.contact_email' => 'sales@mudarisy.test', 'tenancy.operator_token' => 'op-secret']);
    }

    /** Each call from another visitor, so the hourly limit does not get in the way of checking fields. */
    private function fromNewAddress(): static
    {
        static $n = 0;

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.(++$n % 250 + 1)]);
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'أحمد علي', 'phone' => '01012345678', 'email' => 'ahmed@example.com',
            'org_name' => 'سنتر النور', 'audience' => 'center', 'students_estimate' => 1200,
            'plan_slug' => 't5', 'message' => 'محتاجين باقة لعدد 1200 طالب و15 مدرس.',
        ];
    }

    public function test_a_valid_request_is_stored_as_new_and_mailed_to_the_contact_address(): void
    {
        Notification::fake();

        $this->postJson(self::URL, $this->body())->assertCreated()
            ->assertExactJson(['message' => 'وصلنا طلبك. هنكلمك على الموبايل في أقرب وقت.']);

        $row = PlanRequest::query()->sole();
        $this->assertSame('custom', $row->type);
        $this->assertSame('new', $row->status);
        $this->assertSame('01012345678', $row->phone);
        $this->assertSame('t5', $row->plan_slug);
        $this->assertSame(1200, $row->students_estimate);
        $this->assertNull($row->school_id);
        $this->assertSame(64, strlen((string) $row->ip_hash));
        Notification::assertSentOnDemand(PlanRequestReceived::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sales@mudarisy.test'
            && $n->planRequest->is($row));
    }

    public function test_the_mail_is_queued_and_built_from_the_fields(): void
    {
        $notification = new PlanRequestReceived(PlanRequest::query()->create($this->body(['type' => 'custom'])));

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $mail = $notification->toMail((object) []);
        $this->assertStringContainsString('أحمد علي', implode("\n", $mail->introLines));
        $this->assertStringContainsString('01012345678', implode("\n", $mail->introLines));
    }

    public function test_without_a_contact_address_the_request_is_still_saved(): void
    {
        Notification::fake();
        config(['landing.contact_email' => '']);

        $this->postJson(self::URL, $this->body())->assertCreated();

        $this->assertSame(1, PlanRequest::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_mail_failure_does_not_fail_the_request(): void
    {
        // A mailer that cannot connect, sent at once (sync queue): the row is still kept and the visitor told it arrived.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->postJson(self::URL, $this->body())->assertCreated();

        $this->assertSame(1, PlanRequest::query()->count());
    }

    public function test_a_bot_filling_the_hidden_field_gets_the_normal_answer_and_nothing_is_stored_or_mailed(): void
    {
        Notification::fake();

        $this->postJson(self::URL, $this->body(['website' => 'http://spam.example', 'phone' => 'x']))->assertCreated()
            ->assertJsonPath('message', 'وصلنا طلبك. هنكلمك على الموبايل في أقرب وقت.');

        $this->assertSame(0, PlanRequest::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_same_phone_and_message_within_a_day_is_not_stored_twice(): void
    {
        Notification::fake();

        $this->postJson(self::URL, $this->body())->assertCreated();
        $this->postJson(self::URL, $this->body(['phone' => '010 1234 5678']))->assertCreated();
        $this->assertSame(1, PlanRequest::query()->count());
        Notification::assertSentOnDemandTimes(PlanRequestReceived::class, 1);

        $this->travel(25)->hours();
        $this->postJson(self::URL, $this->body())->assertCreated();
        $this->assertSame(2, PlanRequest::query()->count());
    }

    public function test_the_sixth_request_from_one_address_in_an_hour_is_refused_in_arabic(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->body(['message' => "طلب رقم {$i} لباقة كبيرة جداً"]))->assertCreated();
        }

        $this->postJson(self::URL, $this->body(['message' => 'طلب سادس لباقة كبيرة']))->assertStatus(429)
            ->assertJsonPath('message', 'طلبات كتير في وقت قصير، جرّب بعد شوية.');
        $this->assertSame(5, PlanRequest::query()->count());
    }

    public function test_bad_fields_are_refused_with_the_arabic_messages(): void
    {
        $this->fromNewAddress()->postJson(self::URL, $this->body(['phone' => '12345']))->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'اكتب رقم موبايل صحيح.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['message' => 'قصير']))->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'اوصف احتياجك في 10 حروف على الأقل.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['message' => str_repeat('ا', 801)]))->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'الوصف طويل، الحد الأقصى 800 حرف.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['message' => 'شوفوا https://a.example و http://b.example و www.c.example']))->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'ماينفعش أكتر من رابطين في الرسالة.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['name' => 'أ']))->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'اكتب اسمك (حرفين على الأقل).');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['email' => 'not-mail']))->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'البريد الإلكتروني غير صحيح.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['audience' => 'bank']))->assertUnprocessable()
            ->assertJsonPath('errors.audience.0', 'اختار نوع نشاطك.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['students_estimate' => 'many']))->assertUnprocessable()
            ->assertJsonPath('errors.students_estimate.0', 'عدد الطلاب لازم يكون رقم صحيح.');
        $this->fromNewAddress()->postJson(self::URL, $this->body(['plan_slug' => 'enterprise']))->assertUnprocessable()
            ->assertJsonPath('errors.plan_slug.0', 'الباقة المختارة غير متاحة.');

        $this->assertSame(0, PlanRequest::query()->count());
    }

    public function test_international_numbers_and_two_links_are_accepted(): void
    {
        Notification::fake();

        $this->postJson(self::URL, $this->body(['phone' => '+966501234567', 'message' => 'موقعنا https://a.example وصفحتنا https://b.example', 'email' => null]))
            ->assertCreated();
        $this->assertSame('+966501234567', PlanRequest::query()->sole()->phone);
    }

    public function test_a_center_address_has_no_plan_requests(): void
    {
        $this->postJson('http://demo.mudarisy.test/api/plan-requests', $this->body())->assertNotFound();
    }

    public function test_the_operator_lists_and_moves_requests_with_the_token_only(): void
    {
        $new = PlanRequest::query()->create($this->body(['type' => 'custom']));
        PlanRequest::query()->create($this->body(['type' => 'custom', 'status' => 'closed', 'phone' => '01112345678']));

        $this->getJson(self::INBOX)->assertUnauthorized();
        $this->withToken('wrong')->patchJson(self::INBOX.'/'.$new->id, ['status' => 'contacted'])->assertUnauthorized();

        $this->withToken('op-secret')->getJson(self::INBOX.'?status=new')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.status', 'new')
            ->assertJsonPath('data.0.phone', '01012345678')
            ->assertJsonPath('meta.counts.new', 1)
            ->assertJsonPath('meta.counts.closed', 1)
            ->assertJsonMissingPath('data.0.ip_hash');

        $this->withToken('op-secret')->patchJson(self::INBOX.'/'.$new->id, ['status' => 'contacted'])->assertOk()
            ->assertJsonPath('status', 'contacted');
        $this->assertSame('contacted', $new->fresh()->status);

        $this->withToken('op-secret')->patchJson(self::INBOX.'/'.$new->id, ['status' => 'lost'])->assertUnprocessable();
        $this->withToken('op-secret')->getJson(self::INBOX.'?status=lost')->assertUnprocessable();
        $this->withToken('op-secret')->getJson(self::INBOX)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_operator_page_has_the_requests_inbox(): void
    {
        $this->get('http://localhost/operator')->assertOk()
            ->assertSee('id="requests"', false)
            ->assertSee('طلبات الباقات');
    }
}
