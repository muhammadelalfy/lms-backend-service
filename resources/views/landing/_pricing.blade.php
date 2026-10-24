{{-- Pricing (الباقات). Everything is rendered here from PlanCatalog so the section works with no JavaScript:
     all three audiences are stacked, the monthly price shows, the FAQ is native <details>. public/landing/pricing.js
     then turns the stack into tabs and adds the billing toggle. No price or limit is written in this file.
     S12 (calculator + custom-plan card/form) is not built yet: see the marked spot below. --}}
@php
    $catalog = app(\Modules\Tenancy\Services\PlanCatalog::class)->cached();
    $promo = $catalog['promo'];
    $plans = collect($catalog['plans'])->keyBy('slug');
    $trialDays = $catalog['trial_days'];
    $monthsFree = 12 - $catalog['yearly_months_billed'];

    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
    $daysText = fn (int $n) => match (true) { $n === 1 => 'يوم', $n === 2 => 'يومين', $n >= 3 && $n <= 10 => $n.' أيام', default => $n.' يوم' };
    $monthsText = fn (int $n) => match (true) { $n === 1 => 'شهر', $n === 2 => 'شهرين', $n >= 3 && $n <= 10 => $n.' شهور', default => $n.' شهر' };
    $limitLine = function (string $key, $v) use ($num) {
        if ($v === null) { return match ($key) { 'students' => 'طلاب بلا حد', default => 'بلا حد' }; }
        return match ($key) {
            'students' => 'حتى '.$num($v).' طالب',
            'teachers' => $num($v).' '.($v === 1 ? 'مدرس' : 'مدرسين'),
            'subjects' => $num($v).' '.($v === 1 ? 'مادة' : 'مواد'),
            'classes_per_subject' => $num($v).' '.($v === 1 ? 'فصل' : 'فصول').' لكل مادة',
            'assistants' => $v === 0 ? 'بدون مساعدين' : $num($v).' '.($v === 1 ? 'مساعد' : 'مساعدين'),
            'storage_gb' => $num($v).' جيجا تخزين',
            default => $num($v),
        };
    };
    $featureLabels = collect($catalog['feature_rows'])->flatMap(fn ($g) => $g['rows'])->pluck('label', 'key');
    // The feature bullets each tab leads with (the only thing that tells center and group apart besides the sign-up kind).
    $highlights = [
        'center' => ['qr_face_attendance', 'installments', 'revenue_reports_per_teacher'],
        'group' => ['sms_whatsapp_messages', 'revenue_reports_per_teacher', 'chat'],
        'solo' => ['access_codes', 'exams', 'youtube_video'],
    ];
    $audiences = collect($catalog['audiences']);
    $tabOrder = $audiences->reverse()->values(); // solo, group, center: RTL reading order of the spec
    $maxStudents = $plans->max(fn ($p) => $p['limits']['students']);
    $faq = [
        ['إزاي التجربة المجانية شغالة؟', 'بتسجّل مركزك وتاخد عنوانك الخاص وتشتغل فوراً لمدة '.$daysText($trialDays).' من غير كارت. لو خلصت المدة، مركزك بيقفل لحد ما تختار باقة، وبياناتك محفوظة.'],
        ['أدفع إزاي؟', 'الدفع الإلكتروني للطلاب متاح حالياً عن طريق فوري، وجيديا وإنستاباي جايين قريباً. اشتراك المنصة بنفعّله معاك بعد التجربة.'],
        ['لو عدّيت عدد الطلاب في باقتي أعمل إيه؟', 'هتقدر تكمّل شغلك، بس مش هتقدر تضيف طلاب جدد إلا بعد ما تترقّى. مفيش حاجة بتتمسح.'],
        ['الفيديوهات والتخزين؟', 'الفيديو بيتحط برابط يوتيوب أو فيميو غير مدرج داخل الدرس، ومش بيخصم من التخزين. التخزين للملفات والمرفقات.'],
        ['أقدر أغيّر الباقة أو ألغي؟', 'تقدر ترقّي أو تنزّل باقتك في أي وقت، والتغيير بيبدأ من الدورة الجاية.'],
        ['هل فيه دعم لو احتجت مساعدة؟', 'أيوه، راسلنا'.($email ? ' على '.$email : '').' ونساعدك تبدأ.'],
    ];
    $ctaHref = $email ? 'mailto:'.$email : '#pricing';
@endphp
<section id="pricing" class="tint pr" data-billing="monthly">
    <div class="wrap">
        <div class="sec-head">
            <div class="num">05</div>
            <h2>باقة على مقاس مركزك</h2>
            <p>من 50 طالب لحد آلاف الطلبة. جرّب {{ $daysText($trialDays) }} ببلاش من غير كارت بنكي، وغيّر باقتك وقت ما تكبر.</p>
        </div>

        {{-- Without JS: plain links to the stacked sections. With JS: replaced by the tabs below. --}}
        <nav class="pr-anchors" aria-label="نوع النشاط">
            @foreach ($tabOrder as $a)
                <a href="#pr-panel-{{ $a['key'] }}">{{ $a['label'] }}</a>
            @endforeach
        </nav>

        <div class="pr-controls">
            <div class="pr-tabs" role="tablist" aria-label="نوع النشاط">
                @foreach ($tabOrder as $a)
                    <button type="button" role="tab" id="pr-tab-{{ $a['key'] }}" class="pr-tab" data-tab="{{ $a['key'] }}"
                            aria-controls="pr-panel-{{ $a['key'] }}" aria-selected="{{ $loop->last ? 'true' : 'false' }}"
                            tabindex="{{ $loop->last ? '0' : '-1' }}">{{ $a['label'] }}</button>
                @endforeach
            </div>
            <div class="pr-billing" role="group" aria-label="مدة الدفع">
                <button type="button" class="pr-bill" data-billing="monthly" aria-pressed="true">شهري</button>
                <button type="button" class="pr-bill" data-billing="yearly" aria-pressed="false">سنوي — وفّر {{ $monthsText($monthsFree) }}</button>
            </div>
        </div>

        @foreach ($audiences as $a)
            @php
                $shown = collect($a['plans'])->map(fn ($s) => $plans[$s]);
                $trialPlan = $plans[$a['trial_plan']];
            @endphp
            <div class="pr-panel{{ $loop->first ? ' is-active' : '' }}" id="pr-panel-{{ $a['key'] }}" role="tabpanel"
                 aria-labelledby="pr-tab-{{ $a['key'] }}" data-audience="{{ $a['key'] }}" data-kind="{{ $a['kind'] }}">
                <h3 class="pr-panel-title">{{ $a['label'] }}</h3>
                <p class="pr-intro">{{ $a['intro'] }}</p>

                <div class="pr-cards">
                    @foreach (collect([$trialPlan])->concat($shown) as $i => $p)
                        @php
                            $isTrial = $i === 0;
                            $isRec = ! $isTrial && $p['slug'] === $a['recommended'];
                            $price = $p['price'];
                        @endphp
                        <article class="pr-card{{ $isTrial ? ' is-trial' : '' }}{{ $isRec ? ' is-rec' : '' }}"
                                 data-plan="{{ $p['slug'] }}" aria-labelledby="pr-{{ $a['key'] }}-{{ $i }}-name">
                            <div class="pr-badges">
                                @if ($isTrial)<span class="pr-badge pr-badge-free">مجاناً {{ $daysText($trialDays) }}</span>@endif
                                @if ($isRec)<span class="pr-badge pr-badge-rec">الأكثر اختياراً</span>@endif
                                @if (! $isTrial && $promo['active'])<span class="pr-badge pr-badge-promo">{{ $promo['label'] }} {{ $promo['percent'] }}%</span>@endif
                            </div>
                            <h4 id="pr-{{ $a['key'] }}-{{ $i }}-name">{{ $isTrial ? 'تجربة مجانية — '.$p['name'] : $p['name'] }}</h4>

                            @if ($isTrial)
                                <div class="pr-price"><strong>مجاناً</strong></div>
                                <p class="pr-note">لمدة {{ $daysText($trialDays) }}، من غير بطاقة بنكية.</p>
                            @else
                                <div class="pr-price p-monthly">
                                    @if ($promo['active'])<s>{{ $num($price['list_monthly']) }}<span class="sr-only">السعر قبل الخصم</span></s>@endif
                                    <strong>{{ $num($price['shown_monthly']) }}</strong> <span>{{ $catalog['currency'] === 'EGP' ? 'جنيه' : $catalog['currency'] }} / شهر</span>
                                </div>
                                <div class="pr-price p-yearly">
                                    @if ($promo['active'])<s>{{ $num($price['list_monthly'] * 12) }}<span class="sr-only">السعر قبل الخصم</span></s>@endif
                                    <strong>{{ $num($price['shown_yearly']) }}</strong> <span>جنيه / سنة</span>
                                </div>
                                <p class="pr-note p-monthly">ادفع شهرياً، أو وفّر بالدفع السنوي.</p>
                                <p class="pr-note p-yearly">يعني {{ $num($price['yearly_per_month']) }} جنيه في الشهر <span class="pr-save">وفّر {{ $monthsText($monthsFree) }}</span></p>
                            @endif

                            <ul class="pr-list">
                                @foreach ($p['limits'] as $key => $v)
                                    <li>{{ $limitLine($key, $v) }}</li>
                                @endforeach
                                @foreach ($highlights[$a['key']] ?? [] as $f)
                                    @php $val = $p['features'][$f] ?? false; @endphp
                                    @if ($val === true)
                                        <li class="pr-feat">{{ $featureLabels[$f] }}</li>
                                    @elseif ($val === 'soon')
                                        <li class="pr-feat pr-feat-soon">{{ $featureLabels[$f] }} <em>قريباً</em></li>
                                    @endif
                                @endforeach
                            </ul>

                            <a class="btn {{ $isRec ? 'btn-gold' : 'btn-navy' }} pr-cta" href="{{ $ctaHref }}" data-signup
                               data-plan="{{ $p['slug'] }}" data-kind-of-card="{{ $isTrial ? 'trial' : 'paid' }}"
                               data-card-name="{{ $p['name'] }}">{{ $isTrial ? 'ابدأ تجربتك المجانية' : 'ابدأ بالباقة دي' }}</a>
                        </article>
                    @endforeach
                    {{-- S12: the "باقة مخصصة" card and the add-on calculator go here (custom-plan form, POST /api/plan-requests). --}}
                </div>

                <h3 class="pr-sub-title">قارن بين الباقات</h3>
                <div class="pr-scroll" role="region" tabindex="0" aria-label="جدول مقارنة الباقات: {{ $a['label'] }}">
                    <table class="pr-table">
                        <caption class="sr-only">مقارنة الباقات لـ{{ $a['label'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">الميزة</th>
                                @foreach ($shown as $p)<th scope="col">{{ $p['name'] }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="pr-row-price"><th scope="row">السعر الشهري (جنيه)</th>
                                @foreach ($shown as $p)<td>@if ($promo['active'])<s>{{ $num($p['price']['list_monthly']) }}<span class="sr-only">السعر قبل الخصم</span></s> @endif<strong>{{ $num($p['price']['shown_monthly']) }}</strong></td>@endforeach
                            </tr>
                            <tr class="pr-row-price"><th scope="row">السعر السنوي (جنيه)</th>
                                @foreach ($shown as $p)<td><strong>{{ $num($p['price']['shown_yearly']) }}</strong></td>@endforeach
                            </tr>
                        </tbody>
                        @foreach ($catalog['feature_rows'] as $g)
                            <tbody>
                                <tr class="pr-group"><th scope="colgroup" colspan="{{ $shown->count() + 1 }}">{{ $g['group'] }}</th></tr>
                                @foreach ($g['rows'] as $row)
                                    <tr><th scope="row">{{ $row['label'] }}</th>
                                        @foreach ($shown as $p)
                                            @if ($row['type'] === 'limit')
                                                @php $v = $p['limits'][$row['key']] ?? null; @endphp
                                                <td>{{ $v === null ? 'غير محدود' : ($v === 0 ? '—' : $num($v)) }}@if ($v === 0)<span class="sr-only">غير متاح</span>@endif</td>
                                            @else
                                                @php $v = $p['features'][$row['key']] ?? false; @endphp
                                                @if ($v === true)<td class="pr-yes"><span aria-label="متاح" role="img">✓</span></td>
                                                @elseif ($v === 'soon')<td class="pr-soon">قريباً</td>
                                                @else<td class="pr-no"><span aria-label="غير متاح" role="img">—</span></td>@endif
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </div>
        @endforeach

        <h3 class="pr-sub-title pr-faq-title">أسئلة شائعة</h3>
        <div class="pr-faq">
            @foreach ($faq as $i => [$q, $ans])
                <details data-faq="{{ $i }}"><summary>{{ $q }}</summary><p>{{ $ans }}</p></details>
            @endforeach
        </div>

        <ul class="pr-foot">
            <li>الأسعار بالجنيه المصري.</li>
            <li>{{ $catalog['vat_included'] ? 'الأسعار شاملة ضريبة القيمة المضافة.' : 'الأسعار غير شاملة ضريبة القيمة المضافة.' }}</li>
            <li>الطالب المحسوب هو أي طالب مسجّل في مركزك.</li>
            <li>ميزة "قريباً" معناها إنها قيد التجهيز وغير متاحة الآن.</li>
            @if ($promo['active'] && $promo['ends_at'])<li>{{ $promo['label'] }} لفترة محدودة، حتى {{ $promo['ends_at'] }}.</li>@endif
        </ul>
        <p class="price-foot">أكتر من {{ $num($maxStudents) }} طالب أو احتياج خاص؟@if ($email) راسلنا على <a href="mailto:{{ $email }}">{{ $email }}</a>@endif</p>

        {{-- No JS: the buttons above are plain links; this says how to start without them. --}}
        <p class="pr-nojs" id="signup">لبدء التجربة المجانية فعّل جافاسكربت في متصفحك@if ($email) أو راسلنا على <a href="mailto:{{ $email }}">{{ $email }}</a>@endif.</p>
    </div>
    <script type="application/json" id="plans-data">@json($catalog + ['reserved_slugs' => config('schools.reserved_slugs')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)</script>
    <script>document.getElementById('pricing').classList.add('pr-js');</script>
</section>
