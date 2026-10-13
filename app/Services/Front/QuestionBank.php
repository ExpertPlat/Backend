<?php

namespace App\Services\Front;

/**
 * Deterministic multiple-choice questions used when AI assistance is not available. Bilingual system text
 * ({ar, en}) resolved per request language by the presenters. At most five questions per case.
 */
final class QuestionBank
{
    private const BANK = [
        'legal' => [
            ['zone', ['أين تفضّل تأسيس النشاط؟', 'Where would you prefer to set up?'], [['منطقة حرة', 'Free zone'], ['برّ رئيسي', 'Mainland'], ['لا أعرف بعد', 'I do not know yet']], 2],
            ['entity', ['ما الشكل القانوني الحالي لنشاطك؟', 'What is your current legal form?'], [['شركة', 'Company'], ['مؤسسة فردية', 'Sole establishment'], ['غير مسجّل بعد', 'Not registered yet']], 0],
        ],
        'tax' => [
            ['revenue', ['ما حجم الإيرادات السنوية المتوقعة؟', 'What annual revenue do you expect?'], [['أقل من مليون', 'Under 1 million'], ['من 1 إلى 5 ملايين', '1 to 5 million'], ['أكثر من 5 ملايين', 'Over 5 million']], 0],
            ['books', ['هل لديك حسابات منفصلة لهذا النشاط؟', 'Do you keep separate books for this activity?'], [['نعم', 'Yes'], ['لا', 'No'], ['غير متأكد', 'Not sure']], 1],
        ],
        'accounting' => [
            ['books', ['هل لديك حسابات منفصلة لهذا النشاط؟', 'Do you keep separate books for this activity?'], [['نعم', 'Yes'], ['لا', 'No'], ['غير متأكد', 'Not sure']], 1],
        ],
        'intellectual_property' => [
            ['brand', ['هل علامتك التجارية مسجّلة؟', 'Is your trademark registered?'], [['مسجّلة في بلد آخر', 'Registered in another country'], ['غير مسجّلة', 'Not registered'], ['قيد التسجيل', 'In progress']], 0],
        ],
        'business' => [
            ['stage', ['في أي مرحلة مشروعك الآن؟', 'What stage is your project at?'], [['فكرة', 'Idea'], ['يعمل في سوق آخر', 'Running in another market'], ['يعمل هنا ويحتاج تطويرًا', 'Running here and needs growth']], 1],
            ['staff', ['كم موظفًا تتوقع؟', 'How many employees do you expect?'], [['1 – 5', '1 – 5'], ['6 – 15', '6 – 15'], ['أكثر من 15', 'More than 15']], 0],
        ],
        'technology' => [
            ['stack', ['ما وضع النظام التقني الحالي؟', 'What is the state of your current system?'], [['لا يوجد نظام بعد', 'No system yet'], ['نظام قائم يحتاج تحسينًا', 'An existing system to improve'], ['نظام قائم فيه مشكلة', 'An existing system with a problem']], 1],
        ],
        'marketing' => [
            ['channel', ['ما قناتك التسويقية الأساسية؟', 'What is your main marketing channel?'], [['وسائل التواصل', 'Social media'], ['البحث والإعلانات', 'Search and ads'], ['لا توجد قناة بعد', 'None yet']], 0],
        ],
        'design' => [
            ['asset', ['ما الذي تحتاج تصميمه؟', 'What do you need designed?'], [['تطبيق أو موقع', 'An app or website'], ['هوية بصرية', 'A visual identity'], ['منتج أو خدمة', 'A product or service']], 0],
        ],
        'career' => [
            ['goal', ['ما هدفك المهني القريب؟', 'What is your near-term career goal?'], [['وظيفة جديدة', 'A new job'], ['ترقية', 'A promotion'], ['تغيير المجال', 'Changing field']], 0],
        ],
        'education' => [
            ['level', ['ما المستوى الدراسي المعني؟', 'Which education level is this about?'], [['مدرسي', 'School'], ['جامعي', 'University'], ['تدريب مهني', 'Professional training']], 1],
        ],
        'translation' => [
            ['volume', ['ما حجم النص تقريبًا؟', 'Roughly how long is the text?'], [['أقل من 5 صفحات', 'Under 5 pages'], ['من 5 إلى 30 صفحة', '5 to 30 pages'], ['أكثر من 30 صفحة', 'Over 30 pages']], 0],
        ],
    ];

    private const TIMING = ['when', ['متى تحتاج البدء؟', 'When do you need to start?'], [['خلال شهر', 'Within a month'], ['من 1 إلى 3 أشهر', '1 to 3 months'], ['بعد 3 أشهر', 'After 3 months']], 1];

    /** @param list<string> $domains ranked domains of the suggested scopes */
    public static function for(array $domains): array
    {
        $out = [];
        $seen = [];
        foreach ($domains as $domain) {
            foreach (self::BANK[$domain] ?? [] as $q) {
                if (count($out) >= 4 || isset($seen[$q[0]])) {
                    continue;
                }
                $seen[$q[0]] = true;
                $out[] = self::shape($q);
            }
        }
        $out[] = self::shape(self::TIMING);

        return $out;
    }

    /** Questions for the catch-all general consultation (no specialty could be inferred). */
    public static function generic(): array
    {
        return array_map(fn ($q) => self::shape($q), [
            ['kind', ['ما طبيعة مشكلتك أقرب إلى؟', 'What is your problem closest to?'], [['قانونية أو عقود', 'Legal or contracts'], ['مالية أو ضريبية', 'Financial or tax'], ['أعمال أو تسويق', 'Business or marketing'], ['شخصية أو أخرى', 'Personal or other']], 0],
            ['goal', ['ما الذي تريد الخروج به من الجلسة؟', 'What do you want out of the session?'], [['فهم خياراتي', 'Understand my options'], ['خطة خطوات واضحة', 'A clear step-by-step plan'], ['توجيه للمختص المناسب', 'A referral to the right specialist']], 1],
            ['status', ['أين وصلت حتى الآن؟', 'Where are you so far?'], [['لم أبدأ بعد', 'Not started yet'], ['بدأت ولدي مستندات', 'Started, I have documents'], ['عالقة وأحتاج مساعدة', 'Stuck and need help']], 0],
            self::TIMING,
        ]);
    }

    private static function shape(array $q): array
    {
        return ['id' => $q[0], 'label' => ['ar' => $q[1][0], 'en' => $q[1][1]], 'options' => array_map(fn ($o) => ['ar' => $o[0], 'en' => $o[1]], $q[2]), 'suggested' => $q[3]];
    }
}
