<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        {{-- mPDF (unlike dompdf) has a real Arabic-script shaping/bidi engine, so plain RTL
             divs reorder correctly both visually AND in the underlying text layer used for
             copy-paste/search — no table-per-paragraph workaround needed here. --}}
        body { font-family: 'tajawal', sans-serif; font-size: 12px; color: #1a1a1a; direction: rtl; }
        .title { text-align: center; font-size: 15px; font-weight: bold; margin-bottom: 30px; }
        {{-- mPDF draws text-decoration:underline at the font's own built-in underline-position
             metric, which sits too close to Arabic descenders/diacritics — not adjustable via
             CSS (text-underline-offset isn't supported). A border-bottom on an inline-block span
             gives full control over the gap instead. --}}
        .intro-line { text-align: right; margin-bottom: 10px; }
        .side-label-line { text-align: left; margin: 8px 0; }
        .terms-intro-line { text-align: center; margin: 28px 0 24px; }
        .intro, .side-label, .terms-intro { display: inline-block; font-weight: bold; border-bottom: 1px solid #1a1a1a; padding-bottom: 4px; }
        .party { margin-bottom: 4px; line-height: 1.45; text-align: right; }
        .party b { font-weight: bold; }
        .blank { display: inline-block; min-width: 160px; border-bottom: 1px dotted #555; padding: 0 4px; text-align: center; }
        {{-- text-align:right, not justify: mPDF justifies Arabic via font-level kashida
             (letter-elongation) data, and Tajawal (a plain UI sans-serif, not built for
             typesetting) doesn't reliably expose that — justify falls back to inserting gaps
             between individual letters, breaking their natural cursive joining. --}}
        .article { margin-bottom: 7px; line-height: 1.45; text-align: right; }
        .article b { font-weight: bold; }
        table.signatures { margin-top: 40px; width: 100%; }
        .sig { width: 50%; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <div class="title">عقد شغل موسمي محدد المدة (القطاع الفلاحي)</div>

    <div class="intro-line"><span class="intro">تم إبرام هذا العقد بين:</span></div>

    <div class="party">
        <b>الطرف الأول:</b> AGRI-INTERIM Sarl مقرها إقامة حمزة زنقة 2 عمارة 2 الشقة 6 بلوك أ بئر الرامي القنيطرة،
        رقم سجلها التجاري 47825، ورقم انخراطها بالصندوق الوطني للضمان الاجتماعي: 3548031،
        والممثلة في شخص ممثلها القانوني (أو من ينوب عنه).
    </div>
    <div class="side-label-line"><span class="side-label">من جهة</span></div>

    <div class="party">
        <b>والطرف الثاني:</b> السيد(ة) <span class="blank">{{ $employee->full_name ?: '' }}</span>
        الحامل(ة) لبطاقة التعريف الوطنية رقم: <span class="blank">{{ $employee->cin ?: '' }}</span>
    </div>
    <div class="party">
        والقاطن(ة) بالعنوان التالي: <span class="blank" style="min-width: 320px;">{{ $employee->address ?: '' }}</span>
    </div>
    <div class="party">
        ومسجل(ة) بالصندوق الوطني للضمان الاجتماعي تحت رقم: <span class="blank">{{ $employee->cnss_number ?: '' }}</span>
    </div>
    <div class="side-label-line"><span class="side-label">من جهة ثانية</span></div>

    <div class="terms-intro-line"><span class="terms-intro">تم التعاقد بين الأطراف على ما يلي:</span></div>

    <div class="article">
        <b>المادة الأولى:</b> يبتدئ هذا العقد من تاريخ <span class="blank">{{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}</span>
        وينتهي بانتهاء النشاط الفلاحي الموسمي أو في حالة حدوث قوة قاهرة أو ارتكاب أحد الأخطاء الجسيمة أو خرق أحد بنود العقد.
    </div>

    <div class="article">
        <b>المادة الثانية:</b> يلتزم الأجير(ة) بأنه حر من أي التزام شغل وبأنه غير متعاقد مع شركة أخرى ابتداء من تاريخ تشغيله،
        كما يلتزم بتسليم يد بيد مع التوقيع في سجل خاص، وحصوله على وصل مقابل ذلك، لكل تغيير في محل سكناه،
        وأنه يتحمل مسؤولية عدم توصله بالرسائل الموجهة إليه إلى مقر السكنى المصرح به.
    </div>

    <div class="article">
        <b>المادة الثالثة:</b> ابتداء من التحاق الأجير(ة) الفعلي بعمله يخضع لفترة اختبار مدتها أسبوعين (15 يوما)،
        ويمكن لكل طرف أن يفسخ العقد خلالها دون أي تعويض طبقا لمدونة الشغل، وفي حالة أي نزاع تبقى محاكم القنيطرة هي المختصة.
    </div>

    <div class="article">
        <b>المادة الرابعة:</b> يلتزم الطرف الثاني بإحترام جميع القواعد العامة والمتعلقة بالإنضباط والحفاظ على النظام العام بالضيعة
        كما هو منصوص عليه في مدونة الشغل والنظام الداخلي للشركة، كما يلتزم بإحترام وتنفيذ الأوامر الصادرة عن مشغله أو رؤسائه المباشرين،
        وبشكل عام يكون الأجير مسؤولا في إطار شغله والمهام المنوط بها عن أفعاله، أو إهماله، أو تقصيره، أو عدم إحتياطه،
        مع إحترام شروط الصحة والسلامة والحفاظ على جميع الممتلكات.
    </div>

    <div class="article">
        <b>المادة الخامسة:</b> يتقاضى الطرف الثاني أجرا يساوي الحد الأدنى للأجر المعمول به في قطاع الفلاحة،
        بعد خصم الاقتطاعات المحددة طبقا للقوانين الجاري بها العمل. ويحق للمشغل أن يكلف المستخدم بعمل آخر
        أو نقله إلى ضيعة أخرى وبذات مرتبه مع إستفادته من جميع الحقوق كما هو منصوص عليها في مدونة الشغل.
    </div>

    <div class="article">
        <b>المادة السادسة:</b> كل تغيب لأكثر من أربعة أيام دون تبرير في غضون (48 ساعة) إلا في حالة القوة القاهرة مع اخذ إذن (الغياب)
        يعرض الطرف الثاني للعقوبات المنصوص عليها في المادة 39 من قانون الشغل.
    </div>

    <div class="article">
        <b>المادة السابعة:</b> يشهد الطرف الثاني أنه اطلع على محتوى هذا العقد ووافق عليه، عن حسن نية دون تدليس أو غبن أو إكراه،
        قبل المصادقة عليه، وأنه توصل بنسخة منه مصادق عليها.
    </div>

    {{-- Unlike dompdf, mPDF correctly reverses <td> column order for an RTL table, so cells are
         written in natural reading order here — Party 1 first — and land with Party 1 on the
         visual right, matching the original contract's layout. --}}
    <table class="signatures"><tr>
        <td class="sig">توقيع الطرف الأول<br/>(الممثل القانوني للشركة)</td>
        <td class="sig">توقيع الطرف الثاني<br/>(الأجير)</td>
    </tr></table>
</body>
</html>
