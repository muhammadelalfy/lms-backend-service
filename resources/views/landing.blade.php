<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('landing.name') }} — منصة إدارة المراكز والتعليم</title>
    <meta name="description" content="مُدرّسى منصة عربية لإدارة المراكز والسناتر: الحضور بالـQR والوجه، الاختبارات، المحادثات والمكالمات، المدفوعات والتقارير، وتطبيق للجوال.">
    <meta name="theme-color" content="#0b1d3a">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('brand/favicon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('landing.name') }} — منصة إدارة المراكز والتعليم">
    <meta property="og:description" content="لكل معلّم وطالب غاية — منصة عربية لإدارة المراكز والسناتر.">
    <meta property="og:image" content="{{ asset('brand/og-image.png') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-900: #08152b;
            --navy-800: #0b1d3a;
            --navy-700: #11284d;
            --navy-600: #1b3a66;
            --navy-100: #e6edf7;
            --navy-50: #f3f6fb;
            --gold-500: #d4af55;
            --gold-600: #8a6616;
            --gold-100: #f8f0d8;
            --cream: #fbf7ee;
            --ink: #0b1d3a;
            --muted: #51607a;
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: 'Tajawal', system-ui, 'Segoe UI', Tahoma, sans-serif;
            color: var(--ink);
            background: #fff;
            line-height: 1.8;
        }
        :root { --header-h: 69px; --marquee-h: 75px; }
        a { color: inherit; text-decoration: none; }
        .wrap { width: min(1180px, 100% - 32px); margin-inline: auto; }

        /* header */
        header.top {
            position: sticky; top: 0; z-index: 10;
            background: rgba(11, 29, 58, .96);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .nav { display: flex; align-items: center; gap: 24px; min-height: 68px; }
        .brand { font-size: 24px; font-weight: 800; color: #fff; letter-spacing: .5px; }
        .brand span { color: var(--gold-500); }
        .brand-link { display: inline-flex; align-items: center; gap: 10px; }
        .brand-link img { height: 46px; width: auto; display: block; }
        .brand-link .brand-name { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: .5px; }
        .brand-logo-footer { width: min(240px, 100%); height: auto; display: block; margin-bottom: 14px; }
        .nav ul { display: flex; gap: 22px; list-style: none; margin: 0; padding: 0; margin-inline-start: auto; }
        .nav ul a { color: #cfd9ea; font-weight: 500; padding: 6px 0; border-bottom: 2px solid transparent; }
        .nav ul a:hover { color: #fff; border-color: var(--gold-500); }
        .nav-actions { display: flex; align-items: center; gap: 10px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 26px; border-radius: 12px; font-weight: 700;
            border: 1.5px solid transparent; cursor: pointer; font-family: inherit; font-size: 16px;
        }
        .btn-gold { background: var(--gold-500); color: var(--navy-800); }
        .btn-gold:hover { background: #e0be6a; }
        .btn-ghost { border-color: rgba(255,255,255,.4); color: #fff; }
        .btn-ghost:hover { border-color: #fff; }
        .btn-white { background: #fff; color: var(--navy-800); }
        .btn[aria-disabled="true"] { opacity: .6; pointer-events: none; }

        /* hero */
        .hero {
            background:
                radial-gradient(900px 400px at 15% 10%, rgba(212,175,85,.18), transparent 60%),
                linear-gradient(160deg, var(--navy-900), var(--navy-700) 70%, var(--navy-600));
            color: #fff;
            /* The first screen is the sticky header, the hero, and the moving strip at its bottom edge.
               --header-h and --marquee-h are kept exact by extras.js; the vh line is the fallback for
               browsers without svh. */
            min-height: calc(100vh - var(--header-h) - var(--marquee-h));
            min-height: calc(100svh - var(--header-h) - var(--marquee-h));
            display: grid; align-content: center;
            padding: clamp(40px, 9vh, 96px) 0 clamp(88px, 14vh, 128px);
        }
        .hero-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 48px; align-items: center; }
        .eyebrow {
            display: inline-block; padding: 4px 14px; border-radius: 999px;
            background: rgba(212,175,85,.16); color: var(--gold-500);
            font-weight: 700; font-size: 14px; margin-bottom: 18px;
        }
        .hero h1 { font-size: clamp(34px, 5vw, 56px); line-height: 1.25; margin: 0 0 18px; font-weight: 800; }
        .hero h1 em { font-style: normal; color: var(--gold-500); }
        .hero p.lead { font-size: 19px; color: #cfd9ea; max-width: 56ch; margin: 0 0 30px; }
        .cta { display: flex; flex-wrap: wrap; gap: 12px; }

        .quote-card {
            border: 1px solid rgba(255,255,255,.14); border-radius: 24px;
            padding: 28px; background: rgba(255,255,255,.04);
        }
        .quote-card .mark { color: var(--gold-500); font-size: 54px; line-height: .6; font-weight: 800; }
        .quote-card p { font-size: 22px; font-weight: 700; margin: 8px 0 14px; }
        .quote-card small { color: #a9b8d2; }

        /* sections */
        section { padding: 80px 0; }
        section.tint { background: var(--navy-50); }
        section.cream { background: var(--cream); }
        .sec-head { margin-bottom: 40px; }
        .sec-head .num { color: var(--gold-600); font-weight: 800; font-size: 15px; }
        .sec-head h2 {
            font-size: clamp(26px, 3.4vw, 38px); margin: 4px 0 0; font-weight: 800;
            display: inline-block; padding-bottom: 10px; border-bottom: 4px solid var(--gold-500);
        }
        .sec-head p { color: var(--muted); max-width: 62ch; margin: 14px 0 0; }

        .grid { display: grid; gap: 20px; }
        .g3 { grid-template-columns: repeat(3, 1fr); }
        .g4 { grid-template-columns: repeat(4, 1fr); }
        .card {
            background: #fff; border: 1px solid var(--navy-100); border-radius: var(--radius);
            padding: 26px; transition: transform .2s, box-shadow .2s;
        }
        .card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(11,29,58,.09); }
        .badge {
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--gold-100); color: var(--gold-600);
            display: grid; place-items: center; font-size: 24px; margin-bottom: 14px;
        }
        .card h3 { margin: 0 0 6px; font-size: 20px; }
        .card p { margin: 0; color: var(--muted); font-size: 16px; }

        /* two products */
        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .panel { border-radius: 24px; padding: 36px; }
        .panel.navy { background: var(--navy-800); color: #fff; }
        .panel.navy p { color: #cfd9ea; }
        .panel.light { background: #fff; border: 1px solid var(--navy-100); }
        .panel h3 { font-size: 26px; margin: 0 0 8px; }
        .panel ul { padding: 0; margin: 18px 0 24px; list-style: none; }
        .panel li { padding-inline-start: 28px; position: relative; margin: 8px 0; }
        .panel li::before { content: '✓'; position: absolute; inset-inline-start: 0; color: var(--gold-500); font-weight: 800; }
        .panel.light li::before { color: var(--gold-600); }

        /* steps */
        .step .n {
            width: 44px; height: 44px; border-radius: 12px; background: var(--navy-800); color: var(--gold-500);
            display: grid; place-items: center; font-weight: 800; font-size: 20px; margin-bottom: 14px;
        }

        /* stats */
        .stats { background: var(--navy-800); color: #fff; padding: 56px 0; }
        .stats .grid { text-align: center; }
        .stats strong { display: block; font-size: 42px; color: var(--gold-500); font-weight: 800; }
        .stats span { color: #cfd9ea; }

        /* download */
        .download { background: var(--gold-100); border-radius: 28px; padding: 48px; text-align: center; }
        .download h2 { margin: 0 0 10px; font-size: 32px; }
        .download p { color: var(--muted); margin: 0 auto 26px; max-width: 54ch; }
        .download .cta { justify-content: center; }
        .download .download-meta { margin: 18px auto 0; font-size: 15px; }
        .download .download-meta a { color: inherit; text-decoration: underline; }
        .btn-navy { background: var(--navy-800); color: #fff; }
        .btn-navy:hover { background: var(--navy-600); }

        /* footer */
        footer { background: var(--navy-900); color: #a9b8d2; padding: 48px 0 28px; }
        footer .cols { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 32px; }
        footer h4 { color: #fff; margin: 0 0 10px; }
        footer a:hover { color: var(--gold-500); }
        footer ul { list-style: none; padding: 0; margin: 0; }
        footer .copy { border-top: 1px solid rgba(255,255,255,.08); margin-top: 32px; padding-top: 18px; font-size: 14px; }

        @media (max-width: 900px) {
            .hero-grid, .split, footer .cols { grid-template-columns: 1fr; }
            .g3, .g4 { grid-template-columns: 1fr 1fr; }
            .nav ul { display: none; }
            .nav .nav-actions { margin-inline-start: auto; }
        }
        @media (max-width: 560px) {
            .nav-actions .btn { padding: 8px 16px; font-size: 15px; white-space: nowrap; }
            .nav-actions .btn-ghost + .btn-gold { display: none; }
            .g3, .g4 { grid-template-columns: 1fr; }
            section { padding: 56px 0; }
            .download { padding: 32px 20px; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .card { transition: none; }
        }
    </style>
    <link rel="stylesheet" href="{{ asset('landing/motion.css') }}">
    <link rel="stylesheet" href="{{ asset('landing/extras.css') }}">
    <link rel="stylesheet" href="{{ asset('landing/entry.css') }}">
    <link rel="stylesheet" href="{{ asset('landing/pricing.css') }}">
</head>
<body>
@php
    $name = config('landing.name');
    $android = config('landing.android_url');
    // An APK published on this server wins over a configured store link.
    $androidRelease = \App\Support\AndroidRelease::fromConfig()->summary();
    $mb = fn (int $bytes) => number_format($bytes / 1048576).' MB';
    $ios = config('landing.ios_url');
    $web = config('landing.web_url');
    $email = config('landing.contact_email');
    // On a school's own address the platform is one click away; the central site has no school to sign in to.
    $login = in_array(request()->getHost(), config('tenancy.central_domains', []), true) ? null : '/login';
    // Where "enter the platform" goes: the school's sign-in, a configured web address, or (on the
    // main site, where no school is chosen yet) nowhere, which opens the school-code box.
    $target = $login ?: ($web ?: null);
    $base = config('tenancy.base_domain');
@endphp

<div class="progress" id="progress" aria-hidden="true"></div>
<header class="top" id="top-bar">
    <div class="wrap nav">
        <a class="brand-link" href="#top" aria-label="{{ $name }}">
            <img src="{{ asset('brand/mudarisy_mark_reversed.png') }}" alt="" width="36" height="46">
            <span class="brand-name">{{ $name }}</span>
        </a>
        <ul id="nav-links">
            <li><a href="#platform">عن المنصة</a></li>
            <li><a href="#app">التطبيق</a></li>
            <li><a href="#features">المزايا</a></li>
            <li><a href="#how">كيف تبدأ</a></li>
            @unless ($target)
                <li><a href="#pricing">الباقات</a></li>
            @endunless
        </ul>
        <div class="nav-actions">
            @include('landing._enter', ['class' => 'btn btn-gold', 'label' => 'ادخل المنصة'])
            <a class="btn btn-ghost" href="#download">حمّل التطبيق</a>
        </div>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="nav-links" aria-label="القائمة">
            <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
        </button>
    </div>
</header>

<main id="top">
    <section class="hero">
        <canvas class="net" id="net" aria-hidden="true"></canvas>
        <div class="grid-bg" aria-hidden="true"></div>
        <div class="blob b1" aria-hidden="true"></div><div class="blob b2" aria-hidden="true"></div><div class="blob b3" aria-hidden="true"></div>
        <div class="wrap hero-grid">
            <div>
                <span class="eyebrow">منصة تعليمية عربية متكاملة</span>
                <h1>أدِر مركزك من مكان واحد، <em>بسهولة وثقة</em></h1>
                <p class="lead">
                    {{ $name }} تجمع الحضور والاختبارات والمحادثات والمدفوعات والتقارير في منصة واحدة
                    لكل مركز أو سنتر، مع تطبيق جوال للإدارة والمعلمين والطلاب وأولياء الأمور.
                </p>
                <div class="cta">
                    @include('landing._enter', ['class' => 'btn btn-gold', 'label' => 'ادخل المنصة'])
                    <a class="btn btn-ghost" href="#download">حمّل التطبيق</a>
                </div>
            </div>
            <aside class="quote-card" aria-label="رسالتنا">
                <div class="mark">“</div>
                <p>تقنية تخدم التعليم، لا تُثقله: وقتٌ أقل في الإدارة ووقتٌ أكثر مع الطلاب.</p>
                <small>فريق {{ $name }}</small>
            </aside>
        </div>
        <a class="scroll-cue" href="#platform" aria-label="انزل لاستكشاف المنصة">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </a>
    </section>

    <div class="marquee" aria-hidden="true">
        <div class="marquee-track"><span class="chip">الحضور بـ QR</span><span class="chip">التعرّف على الوجه</span><span class="chip">الاختبارات</span><span class="chip">المحادثات</span><span class="chip">المكالمات</span><span class="chip">المدفوعات</span><span class="chip">التقارير</span><span class="chip">الإشعارات</span><span class="chip">الواجبات</span><span class="chip">المجموعات</span><span class="chip">يعمل دون إنترنت</span><span class="chip">أندرويد وiOS</span><span class="chip">الحضور بـ QR</span><span class="chip">التعرّف على الوجه</span><span class="chip">الاختبارات</span><span class="chip">المحادثات</span><span class="chip">المكالمات</span><span class="chip">المدفوعات</span><span class="chip">التقارير</span><span class="chip">الإشعارات</span><span class="chip">الواجبات</span><span class="chip">المجموعات</span><span class="chip">يعمل دون إنترنت</span><span class="chip">أندرويد وiOS</span></div>
    </div>

    <section id="platform">
        <div class="wrap">
            <div class="sec-head">
                <div class="num">01</div>
                <h2>منصة {{ $name }}</h2>
                <p>لكل مركز نطاقها وبياناتها المنفصلة، وتحكّم كامل في الصلاحيات والباقات والإعدادات.</p>
            </div>
            <div class="grid g3">
                <article class="card"><div class="badge">🏫</div><h3>مراكز كثيرة، منصة واحدة</h3><p>قاعدة بيانات ونطاق فرعي لكل مركز، فتبقى بياناتها معزولة وآمنة.</p></article>
                <article class="card"><div class="badge">🧩</div><h3>وحدات تُفعَّل حسب الحاجة</h3><p>اختر الوحدات التي تحتاجها مركزك وفعّلها أو أوقفها في أي وقت.</p></article>
                <article class="card"><div class="badge">🔐</div><h3>أدوار وصلاحيات</h3><p>إدارة ومعلمون وطلاب وأولياء أمور، لكلٍّ ما يحتاجه فقط.</p></article>
            </div>
        </div>
    </section>

    <section id="app" class="tint">
        <div class="wrap">
            <div class="sec-head">
                <div class="num">02</div>
                <h2>تطبيق {{ $name }}</h2>
                <p>تطبيق عربي من اليمين إلى اليسار لنظامي أندرويد وiOS، يعمل مع مركزك برمز المركز.</p>
            </div>
            <div class="split">
                <div class="panel navy">
                    <h3>للإدارة والمعلمين</h3>
                    <p>اعمل من هاتفك أثناء اليوم الدراسي.</p>
                    <ul>
                        <li>تسجيل الحضور بمسح QR أو بالتعرّف على الوجه</li>
                        <li>إدارة المجموعات والطلاب واستيرادهم من ملف Excel</li>
                        <li>إنشاء الاختبارات وأوراق العمل ومتابعة النتائج</li>
                    </ul>
                    <a class="btn btn-gold" href="#download">حمّل التطبيق</a>
                </div>
                <div class="panel light">
                    <h3>للطلاب وأولياء الأمور</h3>
                    <p>تابع الدراسة والتواصل في مكان واحد.</p>
                    <ul>
                        <li>إشعارات فورية بالحضور والغياب والمستجدات</li>
                        <li>محادثات ومكالمات مع المعلمين والفصول المباشرة</li>
                        <li>الاختبارات والمدفوعات والتقارير الدورية</li>
                    </ul>
                    <a class="btn btn-navy" href="#download">ابدأ الآن</a>
                </div>
            </div>
        </div>
    </section>

    <section id="features">
        <div class="wrap">
            <div class="sec-head">
                <div class="num">03</div>
                <h2>مزايا تغطي يوم المركز</h2>
            </div>
            <div class="grid g4">
                <article class="card"><div class="badge">✅</div><h3>الحضور</h3><p>QR ووجه وغياب تلقائي مع إشعار ولي الأمر.</p></article>
                <article class="card"><div class="badge">📝</div><h3>الاختبارات</h3><p>اختبارات إلكترونية وتصحيح ونتائج فورية.</p></article>
                <article class="card"><div class="badge">💬</div><h3>التواصل</h3><p>محادثات ومكالمات وحصص مباشرة.</p></article>
                <article class="card"><div class="badge">💳</div><h3>المدفوعات</h3><p>رسوم وأقساط وسداد إلكتروني وإيصالات.</p></article>
                <article class="card"><div class="badge">📊</div><h3>التقارير</h3><p>لوحات متابعة وتقارير للإدارة وأولياء الأمور.</p></article>
                <article class="card"><div class="badge">🔔</div><h3>الإشعارات</h3><p>دفع وSMS وواتساب بحسب إعدادات كل مركز.</p></article>
                <article class="card"><div class="badge">👥</div><h3>المجموعات</h3><p>فصول ومجموعات وجداول ومواعيد.</p></article>
                <article class="card"><div class="badge">📴</div><h3>الوضع المحلي</h3><p>جرّب التطبيق كاملاً ببيانات تجريبية دون خادم.</p></article>
            </div>
        </div>
    </section>

    <section id="tour" class="tour">
        <div class="tour-bg" aria-hidden="true"><span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span></div>
        <div class="wrap">
            <div class="sec-head">
                <div class="num">★</div>
                <h2>جولة سريعة</h2>
                <p>اسحب أو استخدم الأسهم لاستعراض ما يمكنك فعله بالتطبيق.</p>
            </div>
            <div class="slider" id="slider" aria-roledescription="carousel">
                <div class="slider-viewport">
                    <div class="slider-track">
                <article class="slide"><div class="slide-num">٠١</div><div class="slide-icon">📱</div><h3>حضور في ثوانٍ</h3><p>امسح بطاقة الطالب بـ QR أو تعرّف على وجهه، ويصل إشعار ولي الأمر فوراً.</p></article>
                <article class="slide"><div class="slide-num">٠٢</div><div class="slide-icon">📴</div><h3>يعمل دون إنترنت</h3><p>أضف الطلاب والواجبات والدفعات بلا اتصال، وتتزامن التغييرات تلقائياً عند عودته.</p></article>
                <article class="slide"><div class="slide-num">٠٣</div><div class="slide-icon">🗓️</div><h3>أجندة المعلّم</h3><p>واجبات المجموعات وملاحظاتك الخاصة لكل مجموعة، بالكتابة أو بالصوت.</p></article>
                <article class="slide"><div class="slide-num">٠٤</div><div class="slide-icon">📝</div><h3>اختبارات ونتائج</h3><p>أنشئ الاختبارات وأوراق العمل وتابع نتائج كل طالب ومجموعة.</p></article>
                <article class="slide"><div class="slide-num">٠٥</div><div class="slide-icon">💬</div><h3>تواصل مباشر</h3><p>محادثات ومكالمات وحصص مباشرة بين المعلمين والطلاب وأولياء الأمور.</p></article>
                    </div>
                </div>
                <div class="slider-bar" aria-hidden="true"><i></i></div>
                <div class="slider-ctrl">
                    <button class="sl-btn" type="button" data-dir="1" aria-label="التالي">→</button>
                    <div class="sl-dots" role="tablist"></div>
                    <button class="sl-btn" type="button" data-dir="-1" aria-label="السابق">←</button>
                </div>
            </div>
        </div>
    </section>

    <section id="how" class="cream">
        <div class="wrap">
            <div class="sec-head">
                <div class="num">04</div>
                <h2>كيف تبدأ</h2>
            </div>
            <div class="grid g3">
                <article class="card step"><div class="n">1</div><h3>سجّل مركزك</h3><p>نفتح لمركزك مساحتها الخاصة ونعدّ الباقة والوحدات.</p></article>
                <article class="card step"><div class="n">2</div><h3>أضف الطلاب والمعلمين</h3><p>ارفع ملف Excel أو أضِف الحسابات يدوياً.</p></article>
                <article class="card step"><div class="n">3</div><h3>حمّل التطبيق</h3><p>أدخل رمز المركز وسجّل الدخول وابدأ العمل.</p></article>
            </div>
        </div>
    </section>

    <div class="stats">
        <div class="wrap grid g4">
            <div><strong>+8</strong><span>وحدات جاهزة</span></div>
            <div><strong>4</strong><span>أدوار للمستخدمين</span></div>
            <div><strong>2</strong><span>نظاما جوال: أندرويد وiOS</span></div>
            <div><strong>RTL</strong><span>عربي من اليمين لليسار</span></div>
        </div>
    </div>

    @unless ($target)
        @include('landing._pricing')
    @endunless

    <section id="download">
        <div class="wrap">
            <div class="download">
                <h2>حمّل تطبيق {{ $name }}</h2>
                <p>أدخل رمز مركزك بعد التثبيت وسجّل الدخول بحسابك.</p>
                <div class="cta">
                    @if ($androidRelease && isset($androidRelease['files']['arm64']))
                        <a class="btn btn-navy" href="{{ url('/download/android') }}" download>أندرويد</a>
                    @elseif ($androidRelease)
                        <a class="btn btn-navy" href="{{ url('/download/android?abi=armv7') }}" download>أندرويد</a>
                    @elseif ($android)
                        <a class="btn btn-navy" href="{{ $android }}" rel="noopener">أندرويد</a>
                    @else
                        <span class="btn btn-navy" aria-disabled="true">أندرويد — قريباً</span>
                    @endif
                    @if ($ios)
                        <a class="btn btn-navy" href="{{ $ios }}" rel="noopener">iOS</a>
                    @else
                        <span class="btn btn-navy" aria-disabled="true">iOS — قريباً</span>
                    @endif
                    @include('landing._enter', ['class' => 'btn btn-gold', 'label' => 'الدخول من المتصفح'])
                </div>
                @if ($androidRelease)
                    <p class="download-meta">
                        أندرويد{{ $androidRelease['version_name'] ? ' · الإصدار '.$androidRelease['version_name'] : '' }}{{ isset($androidRelease['files']['arm64']) ? ' · '.$mb($androidRelease['files']['arm64']['size']) : '' }}
                        @if (isset($androidRelease['files']['armv7']) && isset($androidRelease['files']['arm64']))
                            · <a href="{{ url('/download/android?abi=armv7') }}" download>لأجهزة أقدم (32-bit)</a>
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="wrap">
        <div class="cols">
            <div>
                <img class="brand-logo-footer" src="{{ asset('brand/mudarisy_logo_reversed.png') }}" alt="{{ $name }} — مُدرّسى" width="240" height="180">
                <p>منصة عربية لإدارة المراكز والسناتر ومراكز التعليم.</p>
            </div>
            <div>
                <h4>الصفحات</h4>
                <ul>
                    <li><a href="#platform">المنصة</a></li>
                    <li><a href="#app">التطبيق</a></li>
                    <li><a href="#features">المزايا</a></li>
                </ul>
            </div>
            <div>
                <h4>تواصل معنا</h4>
                <ul>
                    @if ($email)
                        <li><a href="mailto:{{ $email }}">{{ $email }}</a></li>
                    @else
                        <li>قريباً</li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="copy">© {{ date('Y') }} {{ $name }}. جميع الحقوق محفوظة.</div>
    </div>
</footer>
@unless ($target)
    <dialog class="school-dialog" id="school-dialog" aria-labelledby="school-title">
        <form class="school-card" id="school-form" novalidate>
            <button type="button" class="school-x" data-close-school aria-label="إغلاق">&times;</button>
            <h2 id="school-title">ادخل إلى مركزك</h2>
            <p class="school-help">اكتب رمز مركزك. هو أول جزء من عنوانها، مثل <b dir="ltr">alnour</b> في <span dir="ltr">alnour.{{ $base }}</span>.</p>
            <label for="school-code">رمز المركز</label>
            <div class="school-field">
                <input id="school-code" name="code" dir="ltr" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="41" required placeholder="alnour">
                <span dir="ltr" aria-hidden="true">.{{ $base }}</span>
            </div>
            <p class="school-status" id="school-status" role="status" aria-live="polite"></p>
            <button class="btn btn-gold school-go" type="submit">متابعة</button>
        </form>
    </dialog>
    @include('landing._signup')
    @include('landing._custom')
@endunless
<script src="{{ asset('vendor/gsap/gsap.min.js') }}"></script>
<script src="{{ asset('vendor/gsap/ScrollTrigger.min.js') }}"></script>
<script src="{{ asset('landing/motion.js') }}"></script>
<script src="{{ asset('landing/extras.js') }}"></script>
<script src="{{ asset('landing/entry.js') }}" defer></script>
@unless ($target)
<script src="{{ asset('landing/pricing.js') }}" defer></script>
@endunless
</body>
</html>
