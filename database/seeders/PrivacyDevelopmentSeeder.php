<?php

namespace Database\Seeders;

use App\Models\PolicyVersion;
use Illuminate\Database\Seeder;
use LogicException;

class PrivacyDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic privacy fixtures are restricted to local/testing.');
        }
        foreach (config('privacy.purposes') as $purpose) {
            foreach (['en', 'ar'] as $locale) {
                if (PolicyVersion::query()->where(['policy_key' => 'demo_'.$purpose, 'version' => 'demo-1', 'locale' => $locale])->exists()) {
                    continue;
                }
                $text = $locale === 'ar' ? 'نص تجريبي للاختبار فقط، ليس سياسة قانونية معتمدة.' : 'SYNTHETIC TEST POLICY ONLY. Not approved legal terms.';
                (new PolicyVersion)->forceFill(['policy_key' => 'demo_'.$purpose, 'purpose' => $purpose, 'version' => 'demo-1',
                    'locale' => $locale, 'content' => $text, 'content_hash' => hash('sha256', $text), 'effective_at' => now()->subDay(),
                    'requires_reconsent' => true, 'is_published' => true, 'created_at' => now()])->save();
            }
        }
    }
}
