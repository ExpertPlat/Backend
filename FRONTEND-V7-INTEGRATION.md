# ربط الباك اند مع فرونت اند v7

## التشغيل محليًا
```bash
composer install
cp .env.example .env && php artisan key:generate
# في .env محليًا:
#   PAYMENTS_DRIVER=fake  CASE_DOCUMENT_SCAN_FAKE=true  QUEUE_CONNECTION=sync
#   MATCHING_TIE_BREAK_KEY=أي-نص-طويل
php artisan migrate:fresh --seed --seeder=DemoSeeder
php artisan serve            # http://localhost:8000
php artisan schedule:work    # إنهاء الجلسات المنتهية (commerce:complete-sessions)
```
الفرونت: `VITE_API_MODE=real` و `VITE_API_BASE_URL=http://localhost:8000`.

كلمة مرور كل الحسابات التجريبية: `Password123!`
| الدور | البريد |
|---|---|
| مستخدم | ahmed@example.com |
| خبراء | sara / karim / tarek / abdullah @example.com |
| أدمن عام | admin@solveit.test |
| أدوار الأدمن | layla (users) · omar (KYC) · hana (support) · yousef (finance) · rana (catalog) @solveit.test |

## سيناريو الحالة الجديد
إنشاء حالة → تحليل (OpenAI عند موافقة المستخدم على المساعدة الذكية، وإلا قواعد + بنك أسئلة) → أسئلة وإجابات → تأكيد وإرسال
→ مطابقة الخبراء (`/api/v2/cases/{id}/matching-runs`) → موجز ودعوة → قبول الخبير → عرض (طلب تعديل / مراجعة / قبول)
→ اتفاق → تمويل (Stripe، أو `fake` محليًا) → حجز موعد → انتهاء الجلسة → تقييم.

## ما تغيّر
- **طبقة استجابة v7**: `app/Http/Front/*` و `app/Http/Controllers/Api/Front/*`، والمسارات القديمة صارت تشير إليها. الشكل `{status, message, data}`. نصوص النظام `{ar, en}` تُترجم حسب `Accept-Language` للمستخدم والخبير، وتبقى كزوج لغتين في لوحة الأدمن لأنها تُحرَّر هناك.
- **جداول جديدة**: `case_analyses`، رسالة الدعوة `case_invitations.message`، والسعر `expert_offerings.price_from_minor/currency`.
- **الخبير**: إضافة الدعوات والعروض والاتفاقات والحجوزات والخدمات وStripe Connect و`/api/expert/me`.
- **الأدمن**: كل القوائم والتفاصيل والإجراءات (المستخدمون، الخبراء، KYC، التجديدات، الكتالوج، المنح، الحالات، طلبات البيانات، الإشراف على التقييمات، المالية، الأدوار والمشرفون).
- **إنهاء الجلسة**: الحجز المؤكد بعد انتهاء موعده يصبح `completed`، ومعه الاتفاق المموّل.

## قرارات مطبّقة
- **الخصوصية (D1)**: الأدمن يرى بيانات الحالة الوصفية فقط. العنوان يظهر كمرجع محايد مثل `حالة CASE-00012`، والملخص وعناوين المستندات `null`.
- **مشاركة الحالة مع الخبير**: تُضبط بموافقة `expert_sharing`، وهي قابلة للسحب.
- **التقييمات**: `REVIEWS_WRITE_ENABLED=true`.
- **العملات**: AED مضافة إلى العملات المسموحة.
- **أدوار جديدة**: `finance_admin` و `catalog_manager`.
- **فصل المهام في الكتالوج**: من ينشئ المسودة لا يراجعها.
- **سجل النشاط**: لا يوجد في الوضع الحقيقي، والفرونت يخفيه.
- **محليًا فقط**: `PAYMENTS_DRIVER=fake` و `CASE_DOCUMENT_SCAN_FAKE=true` و `DemoSeeder`. لا تُفعَّل في الإنتاج.

## وضع التجربة (المستثمر / Demo) — للتجربة فقط
الهدف: ألا يتعطل المستثمر أبدًا. كل ما يلي لمرحلة التجربة، ويُطفأ عند الإطلاق الفعلي.

**تشغيله (محليًا أو على Laravel Cloud):**
```bash
php artisan migrate --force
php artisan demo:seed-investor --force     # آمن للتكرار (idempotent)، يعمل في production صراحةً
# محليًا يكفي: php artisan migrate:fresh --seed --seeder=DemoSeeder
```
الفرونت: `VITE_DEMO_MODE=true` (الافتراضي في `.env.example`). عند الإطلاق: احذفه أو اجعله `false` ثم نفّذ `php artisan demo:disable`.

**ماذا أُضيف:**
- تخصصات جديدة: عقارات، عمل وموارد بشرية، عقود، أسرة، هجرة وإقامة، تسويق ونمو (بجانب التأسيس والضرائب والملكية الفكرية والأعمال).
- تغطية 5 دول (الإمارات، السعودية، مصر، الأردن، الكويت): الخدمات المنظَّمة `MULTI_COUNTRY`، وكل خبير يملك نطاقًا موثّقًا لكل دولة يغطيها.
- **الاستشارة العامة** `general_consultation`: إذا لم يطابق وصف المستخدم أي تخصص تظهر مفعّلة تلقائيًا مع شرح لطيف بأن المنصة في مرحلة تجربة وعدد الخبراء محدود؛ وإذا طابق تخصصًا تظهر كخيار اختياري غير مفعّل. تُرجع الـ API الحقل `isGeneral` في كل scope.
- 13 خبيرًا تجريبيًا (منهم مستشارَان عامّان) بدون صور، ويظهر `isDemo: true` في بطاقة الخبير للمستخدم أيضًا.
- مستخدم تجريبي ثانٍ: `investor@example.com` بجانب `ahmed@example.com`.

**حسابات التجربة (كلمة المرور `Password123!`):**
| الدور | الحسابات |
|---|---|
| مستخدم | investor@ · ahmed@ `example.com` |
| خبراء | sara · karim · tarek · abdullah · mona · faisal · rania · khaled · hind · nour · layan · dalia · basel `@example.com` |
| إدارة | admin · omar · rana · yousef · hana · layla `@solveit.test` |

**أوامر:** `demo:seed-investor [--no-admins] [--force]` و `demo:disable` (يوقف حسابات الديمو ويسحب التوكنات). لا تترك كلمة المرور العامة على نشر حقيقي.

**نتيجة الاختبار:** 18 وصفًا متنوعًا (عربي/إنجليزي/غامض/خارج النطاق/كل دولة) انتهى كل منها بخبير على الأقل، ورحلة كاملة بحساب المستثمر: تحليل ← مطابقة ← دعوة ← عرض ← تعديل ← اتفاق ← دفع ← حجز.
