<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\ExpertKycApplicationStatus;
use App\Enums\ExpertKycStatus;
use App\Models\Admin;
use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertAvailabilitySetting;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertKycApplication;
use App\Models\ExpertKycCredential;
use App\Models\ExpertKycDocument;
use App\Models\ExpertOffering;
use App\Models\ExpertProfile;
use App\Models\ExpertVerifiedScope;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Models\UserConsentRecord;
use App\Models\UserPreference;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogMutex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Local demo world for the v7 frontend ("Ahmed opens a branch in the UAE").
 *
 * Everything is created in the same shape the real workflows produce (KYC-verified scopes with reviewed licence
 * evidence, catalog grants, published profiles, availability and offerings), so matching, invitations, proposals and
 * agreements run through the real domain rules. Restricted to local/testing: it enables regulated demo services.
 *
 *   php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Accounts (password for all: Password123!):
 *   user    ahmed@example.com
 *   experts sara@example.com, karim@example.com, tarek@example.com, abdullah@example.com
 *   admins  admin@solveit.test (super_admin), layla@solveit.test (user_manager), omar@solveit.test (kyc_reviewer),
 *           hana@solveit.test (support_admin), yousef@solveit.test (finance_admin), rana@solveit.test (catalog_manager)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    /** Five-country coverage used by every regulated demo service. */
    public const JURISDICTIONS = ['AE' => 'AE-FEDERAL', 'SA' => 'SA-NATIONAL', 'EG' => 'EG-NATIONAL', 'JO' => 'JO-NATIONAL', 'KW' => 'KW-NATIONAL'];

    private const COUNTRY_NAMES = ['AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'EG' => 'Egypt', 'JO' => 'Jordan', 'KW' => 'Kuwait'];

    /** Entry code of the catch-all service: it guarantees a case always reaches an expert during the trial. */
    public const GENERAL_CODE = 'general_consultation';

    /** Demo catalog: key => [entry code, domain, specialty, service, labels, regulated, jurisdictions, keywords]. */
    private const SERVICES = [
        'legal' => ['legal_company_setup_ae', 'legal', 'legal_company_setup', 'legal_company_setup_consultation',
            ['ar' => 'قانوني: التأسيس والتراخيص', 'en' => 'Legal: set-up and licences'], true, 'ALL',
            ['قانون', 'قانوني', 'ترخيص', 'تراخيص', 'رخصة', 'تأسيس', 'إجراءات', 'اجراءات', 'شركة', 'سجل تجاري', 'legal', 'licence', 'license', 'company setup', 'incorporat', 'register a company']],
        'tax' => ['tax_accounting_ae', 'tax', 'tax_vat_corporate', 'tax_vat_corporate_consultation',
            ['ar' => 'ضرائب ومحاسبة', 'en' => 'Tax & accounting'], true, 'ALL',
            ['ضريب', 'ضرائب', 'محاسب', 'القيمة المضافة', 'حسابات', 'زكاة', 'tax', 'vat', 'accounting', 'bookkeeping']],
        'ip' => ['ip_trademarks_ae', 'intellectual_property', 'ip_trademarks', 'ip_trademarks_consultation',
            ['ar' => 'ملكية فكرية وعلامات تجارية', 'en' => 'Trademarks & IP'], true, 'ALL',
            ['علامة', 'علامتي', 'العلامة التجارية', 'ملكية فكرية', 'براءة', 'حقوق النشر', 'trademark', 'brand', 'intellectual property', 'patent', 'copyright']],
        'real_estate' => ['real_estate_legal', 'legal', 'real_estate_law', 'real_estate_law_consultation',
            ['ar' => 'قانوني: العقارات', 'en' => 'Legal: real estate'], true, 'ALL',
            ['عقار', 'عقاري', 'شقة', 'إيجار', 'ايجار', 'عقد إيجار', 'تملك', 'أرض', 'مالك', 'مستأجر', 'real estate', 'property', 'lease', 'rent', 'landlord', 'tenant', 'apartment']],
        'labor' => ['labor_employment_legal', 'legal', 'labor_employment_law', 'labor_employment_law_consultation',
            ['ar' => 'قانوني: العمل والموارد البشرية', 'en' => 'Legal: labour & HR'], true, 'ALL',
            ['موظف', 'موظفين', 'عامل', 'فصل', 'تعسفي', 'راتب', 'رواتب', 'مكافأة نهاية الخدمة', 'عقد عمل', 'موارد بشرية', 'توظيف', 'employment', 'employee', 'labor', 'labour', 'dismissal', 'termination', 'salary', 'end of service', 'hr']],
        'contracts' => ['contracts_review_legal', 'legal', 'contracts_law', 'contracts_law_consultation',
            ['ar' => 'قانوني: العقود والاتفاقيات', 'en' => 'Legal: contracts & agreements'], true, 'ALL',
            ['عقد', 'عقود', 'اتفاقية', 'شراكة', 'صياغة', 'بند', 'تفاوض', 'contract', 'agreement', 'nda', 'partnership agreement', 'draft', 'clause']],
        'family' => ['family_law_legal', 'legal', 'family_law', 'family_law_consultation',
            ['ar' => 'قانوني: الأسرة والأحوال الشخصية', 'en' => 'Legal: family & personal status'], true, 'ALL',
            ['زواج', 'طلاق', 'حضانة', 'نفقة', 'ميراث', 'إرث', 'وصية', 'أسرة', 'أحوال شخصية', 'divorce', 'custody', 'alimony', 'inheritance', 'will', 'marriage', 'family']],
        'immigration' => ['immigration_residency_legal', 'legal', 'immigration_residency', 'immigration_residency_consultation',
            ['ar' => 'قانوني: الهجرة والإقامة', 'en' => 'Legal: immigration & residency'], true, 'ALL',
            ['إقامة', 'اقامة', 'تأشيرة', 'فيزا', 'جنسية', 'هجرة', 'كفيل', 'الإقامة الذهبية', 'residency', 'visa', 'immigration', 'golden visa', 'work permit', 'citizenship', 'sponsor']],
        'biz' => ['business_expansion_global', 'business', 'business_operational_expansion', 'business_operational_expansion_advisory',
            ['ar' => 'استشارات أعمال وتوسّع', 'en' => 'Business & expansion advice'], false, [],
            ['توسع', 'توسّع', 'فرع', 'السوق', 'سوق', 'نموذج عمل', 'نموذج عملي', 'مشروع', 'expansion', 'expand', 'branch', 'market', 'business model']],
        'marketing' => ['marketing_growth_global', 'marketing', 'marketing_growth', 'marketing_growth_advisory',
            ['ar' => 'تسويق ونمو', 'en' => 'Marketing & growth'], false, [],
            ['تسويق', 'إعلان', 'إعلانات', 'حملة', 'سوشيال', 'وسائل التواصل', 'مبيعات', 'نمو', 'عملاء', 'marketing', 'ads', 'campaign', 'social media', 'growth', 'sales', 'leads']],
        'general' => [self::GENERAL_CODE, 'business', 'general_advisory', 'general_advisory_consultation',
            ['ar' => 'استشارة عامة', 'en' => 'General consultation'], false, [], []],
    ];

    /**
     * Demo experts. `offers`: service key => country codes it is verified for ('GLOBAL' for non-jurisdictional services).
     * Together they cover every regulated service in all five countries, plus generalists as the guaranteed fallback.
     */
    private const EXPERTS = [
        'sara' => ['name' => 'سارة المنصوري', 'email' => 'sara@example.com', 'slug' => 'sara-al-mansoori', 'country' => 'AE', 'role' => 'attorney',
            'offers' => ['legal' => ['AE', 'SA', 'KW'], 'contracts' => ['AE', 'SA', 'KW'], 'immigration' => ['AE', 'SA', 'KW']],
            'title' => 'محامية شركات وتراخيص — تأسيس الفروع والمناطق الحرة', 'years' => 12, 'photo' => 'expert-sara.jpg',
            'bio' => 'أعمل منذ أكثر من اثني عشر عامًا مع التجار والمستثمرين القادمين إلى الإمارات: أحدد الشكل القانوني الأنسب، وأجهّز قائمة المستندات والخطوات الزمنية، وأشرح كل إجراء بلغة واضحة.',
            'specialties' => ['تأسيس الشركات', 'التراخيص التجارية', 'المناطق الحرة', 'عقود التوريد'], 'price' => 450000, 'regulator' => 'UAE Ministry of Justice', 'licence' => 'رخصة مزاولة مهنة المحاماة'],
        'karim' => ['name' => 'كريم فؤاد', 'email' => 'karim@example.com', 'slug' => 'karim-fouad', 'country' => 'AE', 'role' => 'tax_advisor',
            'offers' => ['tax' => ['AE', 'SA', 'KW']],
            'title' => 'محاسب قانوني وخبير ضرائب', 'years' => 10, 'photo' => 'expert-ahmad.jpg',
            'bio' => 'محاسب قانوني يغطي ضريبة القيمة المضافة وضريبة الشركات في الإمارات، ويساعد الشركات القادمة من المنطقة على فصل حسابات الفرع عن الشركة الأم.',
            'specialties' => ['ضريبة القيمة المضافة', 'ضريبة الشركات', 'هيكلة الحسابات'], 'price' => 320000, 'regulator' => 'UAE Federal Tax Authority', 'licence' => 'تسجيل وكيل ضريبي'],
        'tarek' => ['name' => 'طارق السعدي', 'email' => 'tarek@example.com', 'slug' => 'tarek-al-saadi', 'country' => 'AE', 'role' => 'trademark_attorney',
            'offers' => ['ip' => ['AE', 'SA', 'EG', 'JO', 'KW']],
            'title' => 'ملكية فكرية وعلامات تجارية', 'years' => 15, 'photo' => 'expert-samer.jpg',
            'bio' => 'متخصص في تسجيل العلامات التجارية الأجنبية في الإمارات وتوسيع حمايتها، ومراجعة عقود الامتياز والتوزيع المرتبطة بالعلامة.',
            'specialties' => ['تسجيل العلامات التجارية', 'فحص التوفر', 'عقود الامتياز'], 'price' => 280000, 'regulator' => 'UAE Ministry of Economy', 'licence' => 'قيد وكيل علامات تجارية'],
        'abdullah' => ['name' => 'عبدالله النعيمي', 'email' => 'abdullah@example.com', 'slug' => 'abdullah-al-nuaimi', 'country' => 'AE', 'role' => 'business_consultant',
            'offers' => ['biz' => ['GLOBAL']],
            'title' => 'مستشار أعمال وتوسّع', 'years' => 14, 'photo' => 'expert-abdullah.jpg',
            'bio' => 'أقيّم ملاءمة نماذج العمل لسلوك المستهلك الإماراتي، وأعدّ تقديرات التكاليف وتوصيات الموقع والتسعير لمتاجر التجزئة القادمة من المنطقة.',
            'specialties' => ['دخول الأسواق', 'نماذج العمل', 'تقدير التكاليف'], 'price' => 380000, 'regulator' => null, 'licence' => 'شهادة مهنية في استشارات الأعمال'],
        'mona' => ['name' => 'منى حسن', 'email' => 'mona@example.com', 'slug' => 'mona-hassan', 'country' => 'EG', 'role' => 'attorney', 'photo' => null,
            'offers' => ['legal' => ['EG', 'JO'], 'contracts' => ['EG', 'JO'], 'real_estate' => ['EG', 'JO'], 'labor' => ['EG', 'JO'], 'family' => ['EG', 'JO'], 'immigration' => ['EG', 'JO']],
            'title' => 'محامية — شركات وعقارات وأحوال شخصية (مصر والأردن)', 'years' => 11,
            'bio' => 'محامية أمام محاكم القاهرة، أرافق الأفراد والشركات في تأسيس الأنشطة وصياغة العقود وقضايا العقار والعمل والأسرة، وأشرح الخيارات القانونية بوضوح.',
            'specialties' => ['تأسيس الشركات', 'العقارات', 'قانون العمل', 'الأحوال الشخصية'], 'price' => 260000, 'regulator' => 'Egyptian Bar Association', 'licence' => 'قيد نقابة المحامين'],
        'faisal' => ['name' => 'فيصل الحربي', 'email' => 'faisal@example.com', 'slug' => 'faisal-al-harbi', 'country' => 'SA', 'role' => 'attorney', 'photo' => null,
            'offers' => ['legal' => ['SA', 'KW'], 'contracts' => ['SA', 'KW'], 'real_estate' => ['SA', 'KW'], 'labor' => ['SA', 'KW'], 'family' => ['SA']],
            'title' => 'محامٍ ومستشار قانوني — السعودية والكويت', 'years' => 13,
            'bio' => 'أعمل مع الشركات الناشئة والعائلية في الرياض والكويت على التراخيص والعقود والعقار وعلاقات العمل، وأقدّم رأيًا قانونيًا عمليًا مختصرًا.',
            'specialties' => ['العقود التجارية', 'تراخيص الاستثمار', 'العقار', 'نظام العمل'], 'price' => 420000, 'regulator' => 'Saudi Bar Association', 'licence' => 'رخصة المحاماة السعودية'],
        'rania' => ['name' => 'رانيا الخوري', 'email' => 'rania@example.com', 'slug' => 'rania-al-khoury', 'country' => 'JO', 'role' => 'attorney', 'photo' => null,
            'offers' => ['family' => ['JO', 'KW', 'AE'], 'immigration' => ['JO', 'KW', 'AE'], 'labor' => ['JO', 'KW', 'AE']],
            'title' => 'محامية أسرة وهجرة وعمل', 'years' => 9,
            'bio' => 'أتولى قضايا الأسرة والإقامة والعمل للأفراد والعائلات المنتقلة بين الأردن والخليج، وأرتّب لهم الخطوات والمستندات بالترتيب الصحيح.',
            'specialties' => ['الأحوال الشخصية', 'الإقامة والتأشيرات', 'نزاعات العمل'], 'price' => 240000, 'regulator' => 'Jordan Bar Association', 'licence' => 'قيد نقابة المحامين الأردنيين'],
        'khaled' => ['name' => 'خالد الصباح', 'email' => 'khaled@example.com', 'slug' => 'khaled-al-sabah', 'country' => 'KW', 'role' => 'attorney', 'photo' => null,
            'offers' => ['real_estate' => ['KW', 'SA'], 'immigration' => ['KW', 'SA'], 'contracts' => ['KW']],
            'title' => 'محامٍ — عقارات وإقامة وعقود (الكويت)', 'years' => 16,
            'bio' => 'خبرة طويلة في عقود العقار والتملك وإجراءات الإقامة في الكويت، ومراجعة العقود قبل التوقيع وتحديد المخاطر.',
            'specialties' => ['عقود العقار', 'الإقامة', 'مراجعة العقود'], 'price' => 390000, 'regulator' => 'Kuwait Lawyers Association', 'licence' => 'قيد جمعية المحامين الكويتية'],
        'hind' => ['name' => 'هند القاسمي', 'email' => 'hind@example.com', 'slug' => 'hind-al-qasimi', 'country' => 'AE', 'role' => 'attorney', 'photo' => null,
            'offers' => ['real_estate' => ['AE'], 'family' => ['AE']],
            'title' => 'محامية عقارات وأسرة (الإمارات)', 'years' => 8,
            'bio' => 'أساعد الملاك والمستأجرين والمشترين في الإمارات على فهم عقودهم وحقوقهم، وأتابع قضايا الأسرة والميراث لغير المواطنين.',
            'specialties' => ['عقود الإيجار', 'شراء العقار', 'الميراث'], 'price' => 350000, 'regulator' => 'UAE Ministry of Justice', 'licence' => 'رخصة مزاولة مهنة المحاماة'],
        'nour' => ['name' => 'نور عبدالرحمن', 'email' => 'nour@example.com', 'slug' => 'nour-abdelrahman', 'country' => 'EG', 'role' => 'tax_advisor', 'photo' => null,
            'offers' => ['tax' => ['EG', 'JO', 'SA']],
            'title' => 'مستشارة ضرائب ومحاسبة (مصر والأردن والسعودية)', 'years' => 10,
            'bio' => 'محاسبة قانونية تساعد الشركات الصغيرة على الالتزام الضريبي والإقرارات الدورية وتنظيم الدفاتر بما يناسب كل بلد.',
            'specialties' => ['الإقرارات الضريبية', 'الزكاة والضريبة', 'تنظيم الدفاتر'], 'price' => 230000, 'regulator' => 'Egyptian Tax Authority', 'licence' => 'قيد مراقب حسابات'],
        'layan' => ['name' => 'ليان البدر', 'email' => 'layan@example.com', 'slug' => 'layan-al-badr', 'country' => 'AE', 'role' => 'marketing_consultant', 'photo' => null,
            'offers' => ['marketing' => ['GLOBAL']],
            'title' => 'مستشارة تسويق ونمو', 'years' => 9,
            'bio' => 'أبني خطط التسويق والنمو للشركات الناشئة: تحديد العميل المستهدف، قنوات الاستحواذ، الميزانية، وقياس النتائج.',
            'specialties' => ['استراتيجية التسويق', 'الإعلانات المدفوعة', 'محتوى ووسائل التواصل'], 'price' => 300000, 'regulator' => null, 'licence' => 'شهادة مهنية في التسويق الرقمي'],
        'dalia' => ['name' => 'داليا النجار', 'email' => 'dalia@example.com', 'slug' => 'dalia-al-najjar', 'country' => 'JO', 'role' => 'general_advisor', 'photo' => null,
            'offers' => ['general' => ['GLOBAL'], 'biz' => ['GLOBAL']],
            'title' => 'مستشارة عامة — توجيه أولي لأي مشكلة', 'years' => 12,
            'bio' => 'أستمع لمشكلتك مهما كان مجالها، أحدد ما إذا كانت قانونية أو مالية أو تشغيلية، وأضع لك خطة واضحة وأوجّهك للمختص المناسب إن لزم.',
            'specialties' => ['توجيه أولي', 'تقييم المشكلة', 'خطة عمل'], 'price' => 200000, 'regulator' => null, 'licence' => 'شهادة مهنية في الاستشارات الإدارية'],
        'basel' => ['name' => 'باسل الشريف', 'email' => 'basel@example.com', 'slug' => 'basel-al-sharif', 'country' => 'EG', 'role' => 'general_advisor', 'photo' => null,
            'offers' => ['general' => ['GLOBAL']],
            'title' => 'مستشار عام — حلول عملية لمشكلات الأعمال والحياة', 'years' => 15,
            'bio' => 'خبرة واسعة في ترتيب الأولويات وفهم المشكلات الغامضة: أحوّل المشكلة إلى خطوات واضحة وأقترح الجهة أو الخبير الأنسب للمتابعة.',
            'specialties' => ['استشارة عامة', 'تنظيم المشكلات', 'خطة التنفيذ'], 'price' => 180000, 'regulator' => null, 'licence' => 'شهادة مهنية في الاستشارات'],
    ];

    /** Demo end users (password: Password123!). */
    private const USERS = [
        ['أحمد سمير', 'ahmed@example.com', 'EG', '+201005550142'],
        ['مستثمر تجريبي', 'investor@example.com', 'AE', '+971500000042'],
    ];

    private const ROLE_PERMISSIONS = [
        'user_manager' => ['admins.viewAny', 'admins.view', 'users.viewAny', 'users.view', 'users.update', 'users.consentMetadata.view', 'dataRequests.viewAny', 'dataRequests.view', 'experts.viewAny', 'experts.view'],
        'support_admin' => ['users.viewAny', 'users.view', 'experts.viewAny', 'experts.view', 'cases.viewAny', 'cases.view', 'reviews.viewAny', 'reviews.moderate', 'support.viewAny', 'support.view', 'support.update'],
        'kyc_reviewer' => ['experts.viewAny', 'experts.view', 'experts.reviewKyc', 'expertRenewals.viewAny', 'expertRenewals.view', 'expertRenewals.viewEvidence', 'expertRenewals.review'],
        'finance_admin' => ['cases.viewAny', 'cases.view', 'users.viewAny', 'users.view', 'experts.viewAny', 'experts.view', 'finance.view', 'finance.reconcile', 'refunds.view', 'refunds.approve', 'disputes.view', 'stripe_accounts.view'],
        'catalog_manager' => ['experts.viewAny', 'experts.view', 'cases.viewAny', 'cases.view', 'catalog.view', 'catalog.manageDrafts', 'catalog.review', 'catalog.publish', 'catalog.pause', 'catalog.viewImpact'],
    ];

    private const ADMINS = [
        ['مدير المنصة', 'admin@solveit.test', 'super_admin'],
        ['ليلى حداد', 'layla@solveit.test', 'user_manager'],
        ['عمر الخطيب', 'omar@solveit.test', 'kyc_reviewer'],
        ['هناء يوسف', 'hana@solveit.test', 'support_admin'],
        ['يوسف عبيدات', 'yousef@solveit.test', 'finance_admin'],
        ['رنا سليم', 'rana@solveit.test', 'catalog_manager'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('DemoSeeder is restricted to local/testing environments. Use `php artisan demo:seed-investor` for a trial deployment.');
        }
        $this->seedWorld(true, true);
    }

    /**
     * Builds the demo world. Idempotent: safe to run repeatedly (used by `demo:seed-investor` on a trial deployment).
     */
    public function seedWorld(bool $withAdmins = true, bool $withPrivacyFixtures = false): void
    {
        $calls = [ServiceCatalogSeeder::class];
        if ($withAdmins) {
            array_unshift($calls, AuthorizationSeeder::class, CasePermissionsSeeder::class, CatalogPermissionsSeeder::class, ExpertRenewalPermissionsSeeder::class,
                FinancePermissionsSeeder::class, PrivacyPermissionsSeeder::class, ReviewPermissionsSeeder::class);
        }
        if ($withPrivacyFixtures) {
            $calls[] = PrivacyDevelopmentSeeder::class;
        }
        $this->call($calls);

        $super = $withAdmins ? $this->admins() : $this->anyAdmin();
        $versions = $this->catalog($super);
        foreach (self::USERS as [$name, $email, $country, $phone]) {
            $this->user($name, $email, $country, $phone);
        }
        foreach (self::EXPERTS as $key => $e) {
            $this->expert($key, $e, $versions, $super);
        }
        $this->command?->info('Demo world ready. Password for every demo account: '.self::PASSWORD);
    }

    /** The KYC/catalog audit columns need an admin id even when demo admins are not requested. */
    private function anyAdmin(): Admin
    {
        return Admin::query()->orderBy('id')->first()
            ?? $this->make(new Admin, ['email' => 'demo-system@solveit.test'], ['name' => 'Demo System', 'password' => Hash::make(Str::random(40)), 'is_active' => false]);
    }

    private function admins(): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $all = Permission::where('guard_name', 'admin')->pluck('name')->all();
        Role::findOrCreate(AdminRole::SuperAdmin->value, 'admin')->syncPermissions($all);
        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            Role::findOrCreate($role, 'admin')->syncPermissions(array_values(array_intersect($permissions, $all)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $super = null;
        foreach (self::ADMINS as $i => [$name, $email, $role]) {
            $admin = $this->make(new Admin, ['email' => $email], ['name' => $name, 'password' => Hash::make(self::PASSWORD), 'is_active' => true]);
            $admin->forceFill(['invitation_accepted_at' => now()->subDays(90 - $i), 'last_login_at' => now()->subHours(3 + $i)])->save();
            $admin->syncRoles([$role]);
            $super ??= $admin;
        }

        return $super;
    }

    /** @return array<string, CatalogVersion> */
    private function catalog(Admin $admin): array
    {
        return DB::transaction(function () use ($admin) {
            CatalogMutex::lock();
            foreach (array_keys(self::JURISDICTIONS) as $country) {
                CatalogNode::where('kind', 'country')->where('code', $country)->update(['status' => 'enabled']);
                $id = CatalogNode::where('kind', 'country')->where('code', $country)->value('id');
                CatalogNode::where('kind', 'jurisdiction')->where('parent_id', $id)->update(['status' => 'enabled']);
            }
            $out = [];
            foreach (self::SERVICES as $key => [$code, $domain, $specialty, $service, $labels, $regulated, $jurisdictions, $keywords]) {
                $codes = $jurisdictions === 'ALL' ? array_values(self::JURISDICTIONS) : $jurisdictions;
                $domainNode = CatalogNode::where('kind', 'domain')->where('code', $domain)->firstOrFail();
                $domainNode->forceFill(['status' => 'enabled'])->save();
                $spec = $this->node('specialty', $specialty, $domainNode->id, $labels);
                $this->node('service', $service, $spec->id, ['ar' => $labels['ar'], 'en' => $labels['en'].' consultation']);
                $policy = ['domain' => $domain, 'specialty' => $specialty, 'serviceType' => $service,
                    'deliveryModes' => ['video_consultation', 'written_consultation', 'document_review'],
                    'jurisdictionMode' => match (count($codes)) { 0 => 'GLOBAL', 1 => 'COUNTRY_SPECIFIC', default => 'MULTI_COUNTRY' }, 'jurisdictionCodes' => $codes,
                    'regulated' => $regulated, 'remoteDeliveryAllowed' => true, 'requiresVerifiedScope' => true, 'requiresProfessionalLicense' => $regulated,
                    'requiredEvidenceType' => 'credential', 'minimumExperts' => 1, 'maximumExperts' => 3, 'intakeSchema' => [],
                    'matchingRules' => ['keywords' => $keywords, 'prohibitedKeywords' => [], 'humanReviewRequired' => false],
                    'commerciallyAvailable' => true, 'operationallyAvailable' => true, 'requiredConsents' => ['terms', 'privacy'], 'requiredDocuments' => [],
                    'effectiveFrom' => now()->subMonth()->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'effectiveUntil' => null];
                $policy = app(\App\Services\Catalog\CatalogPolicy::class)->validate($policy);
                $hash = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
                $entry = $this->make(new CatalogEntry, ['code' => $code]);
                $version = CatalogVersion::where('entry_id', $entry->id)->orderByDesc('version')->first();
                if (! $version || $version->status !== 'enabled' || ($version->policy_hash !== $hash && $this->stale($version, $policy))) {
                    $version = new CatalogVersion;
                    $version->forceFill(['entry_id' => $entry->id, 'version' => (int) CatalogVersion::where('entry_id', $entry->id)->max('version') + 1, 'revision' => 1,
                        'created_by' => $admin->id, 'reviewed_by' => $admin->id, 'published_by' => $admin->id, 'status' => 'enabled', 'policy' => $policy,
                        'policy_hash' => $hash, 'reviewed_at' => now(), 'published_at' => now()])->save();
                }
                $entry->forceFill(['status' => 'enabled', 'current_version_id' => $version->id])->save();
                $out[$key] = $version;
            }
            CatalogMutex::changed();

            return $out;
        });
    }

    /** A re-run only republishes when a policy field that matters for matching changed (dates drift every run). */
    private function stale(CatalogVersion $v, array $policy): bool
    {
        $keys = ['jurisdictionMode', 'jurisdictionCodes', 'regulated', 'deliveryModes', 'matchingRules', 'domain', 'specialty'];

        return array_intersect_key((array) $v->policy, array_flip($keys)) != array_intersect_key($policy, array_flip($keys));
    }

    private function node(string $kind, string $code, int $parent, array $labels): CatalogNode
    {
        $node = CatalogNode::where('kind', $kind)->where('code', $code)->first() ?? new CatalogNode;
        $node->forceFill(['kind' => $kind, 'code' => $code, 'parent_id' => $parent, 'labels' => $labels, 'status' => 'enabled'] + ($node->exists ? [] : ['version' => 1]))->save();

        return $node;
    }

    private function user(string $name, string $email, string $country, string $phone): void
    {
        $user = $this->make(new User, ['email' => $email], ['name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()->subDays(40)])->save();
        $this->one(UserProfile::class, 'user_id', $user->id)->forceFill(['user_id' => $user->id, 'phone' => $phone, 'country' => $country, 'language' => 'ar', 'timezone' => 'Asia/Dubai', 'version' => 1])->save();
        $this->one(UserPreference::class, 'user_id', $user->id)->forceFill(['user_id' => $user->id, 'contact_channels' => ['email'], 'ai_assistance_enabled' => true, 'recording_preference' => false, 'context_visibility' => 'private', 'version' => 1])->save();
        foreach (['terms', 'privacy', 'ai_assistance'] as $purpose) {
            $policy = PolicyVersion::where('purpose', $purpose)->where('locale', 'ar')->where('is_published', true)->orderByDesc('effective_at')->first();
            if ($policy && ! UserConsentRecord::where(['user_id' => $user->id, 'purpose' => $purpose])->exists()) {
                (new UserConsentRecord)->forceFill(['user_id' => $user->id, 'purpose' => $purpose, 'policy_version_id' => $policy->id, 'decision' => 'granted',
                    'decided_at' => now()->subDays(40), 'source' => 'registration', 'request_id' => (string) Str::uuid()])->save();
            }
        }
    }

    /** @param array<string, CatalogVersion> $versions */
    private function expert(string $key, array $e, array $versions, Admin $admin): void
    {
        // domain => country|GLOBAL => [service keys]
        $plan = [];
        foreach ($e['offers'] as $svc => $countries) {
            foreach ($countries as $c) {
                $plan[$versions[$svc]->policy['domain']][$c][] = $svc;
            }
        }
        $homeDomain = array_key_first($plan);
        $expert = $this->make(new Expert, ['email' => $e['email']], ['name' => $e['name'], 'password' => Hash::make(self::PASSWORD), 'is_active' => true, 'kyc_status' => ExpertKycStatus::Approved]);
        $expert->forceFill(['email_verified_at' => now()->subDays(300), 'is_active' => true, 'is_demo' => true, 'kyc_status' => ExpertKycStatus::Approved, 'country' => $e['country'], 'language' => 'ar', 'domain' => $homeDomain])->save();

        $multi = count(array_unique(array_merge(...array_values(array_map('array_keys', $plan))))) > 1;
        $app = ExpertKycApplication::where('expert_id', $expert->id)->orderBy('attempt_number')->first() ?? new ExpertKycApplication;
        if (! $app->exists) {
            $app->forceFill(['reference' => 'KYC-'.now()->format('Y').'-'.str_pad((string) $expert->id, 4, '0', STR_PAD_LEFT), 'expert_id' => $expert->id, 'attempt_number' => 1,
                'status' => ExpertKycApplicationStatus::Verified, 'full_name' => $e['name'], 'email_snapshot' => $e['email'], 'country' => $e['country'], 'language' => 'ar',
                'domain' => $homeDomain, 'jurisdiction' => $multi ? 'Multi-country' : (self::COUNTRY_NAMES[$e['country']] ?? 'Global'), 'payout_readiness' => 'ready',
                'submitted_at' => now()->subDays(320), 'review_started_at' => now()->subDays(318), 'decided_at' => now()->subDays(300), 'reviewed_by_admin_id' => $admin->id])->save();
        }
        $credential = ExpertKycCredential::where('application_id', $app->id)->first() ?? new ExpertKycCredential;
        if (! $credential->exists) {
            $credential->forceFill(['application_id' => $app->id, 'type' => 'license', 'name' => $e['licence'], 'issuer' => $e['regulator'] ?? 'Professional body',
                'issue_date' => now()->subYears(6)->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(), 'sort_order' => 1])->save();
        }
        $docs = [];
        foreach (['identity' => null, 'cv' => null, 'credential' => $credential->id] as $type => $credentialId) {
            $doc = ExpertKycDocument::where(['application_id' => $app->id, 'document_type' => $type])->first() ?? new ExpertKycDocument;
            if (! $doc->exists) {
                $path = "demo/{$expert->id}/{$type}.pdf";
                Storage::disk((string) config('kyc.disk'))->put($path, $this->pdf("SolveIt demo {$type} document"));
                $doc->forceFill(['application_id' => $app->id, 'document_type' => $type, 'credential_id' => $credentialId, 'disk' => (string) config('kyc.disk'), 'path' => $path,
                    'original_name' => "{$type}.pdf", 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 2048, 'checksum' => hash('sha256', $path),
                    'sort_order' => count($docs) + 1, 'reviewed_at' => now()->subDays(301), 'reviewed_by_admin_id' => $admin->id])->save();
            }
            $docs[$type] = $doc;
        }

        foreach ($plan as $domain => $byCountry) {
            foreach ($byCountry as $country => $services) {
                $isGlobal = $country === 'GLOBAL';
                $scope = ExpertVerifiedScope::where(['expert_id' => $expert->id, 'domain' => $domain])->where(fn ($q) => $isGlobal ? $q->whereNull('verified_country') : $q->where('verified_country', $country))->first() ?? new ExpertVerifiedScope;
                if (! $scope->exists) {
                    $scope->forceFill(['expert_id' => $expert->id, 'kyc_application_id' => $app->id, 'verified_by_admin_id' => $admin->id, 'domain' => $domain,
                        'jurisdiction' => $isGlobal ? 'Global' : self::COUNTRY_NAMES[$country], 'role' => $e['role'], 'service_types' => ['video_consultation', 'written_consultation', 'document_review'],
                        'languages' => ['ar', 'en'], 'status' => 'active', 'valid_from' => now()->subDays(300)->toDateString(), 'valid_until' => now()->addYear()->toDateString(),
                        'evidence_type' => 'credential', 'evidence_id' => $credential->id, 'evidence_document_id' => $docs['credential']->id, 'verified_country' => $isGlobal ? null : $country,
                        'regulator' => $e['regulator'], 'registration_number' => $e['regulator'] ? 'REG-'.(100200 + $expert->id).($isGlobal ? '' : '-'.$country) : null,
                        'verification_source' => $e['regulator'] ? 'https://example.com/registry' : null, 'status_checked' => 'active', 'checked_at' => now()->subDays(30),
                        'next_review_at' => now()->addMonths(6)->toDateString()])->save();
                }
                foreach ($services as $svc) {
                    $version = $versions[$svc];
                    $code = $isGlobal ? 'GLOBAL' : self::JURISDICTIONS[$country];
                    $grant = ExpertCatalogGrant::where(['scope_id' => $scope->id, 'catalog_version_id' => $version->id, 'jurisdiction_code' => $code])->first() ?? new ExpertCatalogGrant;
                    if (! $grant->exists) {
                        $grant->forceFill(['scope_id' => $scope->id, 'catalog_version_id' => $version->id, 'jurisdiction_code' => $code, 'reviewed_by' => $admin->id,
                            'evidence_type' => 'credential', 'evidence_id' => $credential->id])->save();
                    }
                }
            }
        }

        $this->profile($expert, $e);
        foreach ($e['offers'] as $svc => $countries) {
            $version = $versions[$svc];
            $codes = $countries === ['GLOBAL'] ? null : array_values(array_map(fn ($c) => self::JURISDICTIONS[$c], $countries));
            $offering = ExpertOffering::where(['expert_id' => $expert->id, 'catalog_entry_id' => $version->entry_id])->first() ?? new ExpertOffering;
            $offering->forceFill(['expert_id' => $expert->id, 'catalog_entry_id' => $version->entry_id, 'delivery_modes' => $version->policy['deliveryModes'], 'languages' => ['ar', 'en'],
                'jurisdiction_codes' => $codes, 'summary' => mb_substr($e['bio'], 0, 300), 'price_from_minor' => $e['price'], 'currency' => 'AED', 'status' => ExpertOffering::PUBLISHED,
                'version' => $offering->version ?: 1, 'published_at' => $offering->published_at ?: now()->subDays(40)])->save();
        }
    }

    private function profile(Expert $expert, array $e): void
    {
        $profile = $this->one(ExpertProfile::class, 'expert_id', $expert->id);
        $data = ['expert_id' => $expert->id, 'slug' => $e['slug'], 'professional_title' => $e['title'], 'bio' => $e['bio'], 'years_experience' => $e['years'],
            'specialties' => $e['specialties'], 'public_languages' => ['ar', 'en'], 'is_published' => true, 'published_at' => $profile->published_at ?: now()->subDays(50)];
        if (! empty($e['photo']) && ! $profile->avatar_path) {
            $photo = database_path('data/demo/'.$e['photo']);
            $avatar = "expert-avatars/{$expert->id}/".Str::random(20).'.jpg';
            Storage::disk('public')->put($avatar, (string) file_get_contents($photo));
            $data += ['avatar_disk' => 'public', 'avatar_path' => $avatar, 'avatar_original_name' => $e['photo'], 'avatar_mime_type' => 'image/jpeg', 'avatar_size' => filesize($photo)];
        }
        $profile->forceFill($data)->save();
        $day = fn (string $d, bool $on) => ['day' => $d, 'enabled' => $on, 'windows' => $on ? [['start' => '10:00', 'end' => '16:00']] : []];
        $this->one(ExpertAvailabilitySetting::class, 'expert_id', $expert->id)->forceFill(['expert_id' => $expert->id, 'timezone' => 'Asia/Dubai', 'service_modes' => ['video_consultation', 'written_consultation', 'document_review'],
            'weekly_schedule' => [$day('mon', true), $day('tue', true), $day('wed', true), $day('thu', true), $day('fri', true), $day('sat', false), $day('sun', false)],
            'blackout_dates' => [], 'max_active_requests' => 5, 'response_time_hours' => 24, 'accepting_new_requests' => true])->save();
    }

    /** firstOrNew that never mass-assigns the lookup key (strict models in production). */
    private function one(string $class, string $column, mixed $value): \Illuminate\Database\Eloquent\Model
    {
        return $class::query()->where($column, $value)->first() ?? new $class;
    }

    /** firstOrCreate that bypasses mass-assignment guards (strict models in production). */
    private function make(\Illuminate\Database\Eloquent\Model $model, array $keys, array $extra = []): \Illuminate\Database\Eloquent\Model
    {
        $found = $model::query()->where($keys)->first();
        if ($found) {
            return $found;
        }
        $model->forceFill($keys + $extra)->save();

        return $model;
    }

    private function pdf(string $text): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj 4 0 obj<</Length 44>>stream\nBT /F1 12 Tf 20 70 Td ({$text}) Tj ET\nendstream endobj 5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
