<?php

namespace App\Notifications\Expert;

use App\Enums\ExpertKycApplicationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycReviewStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly ExpertKycApplicationStatus $status,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $arabic = $notifiable->language === 'ar';
        $copy = $this->copy($arabic);
        $url = config('expert.frontend.kyc_url')
            ?: rtrim((string) config('app.url'), '/').'/expert/kyc';

        return (new MailMessage)
            ->subject($copy['subject'])
            ->greeting($arabic ? 'مرحبًا '.$notifiable->name : 'Hello '.$notifiable->name)
            ->line($copy['line'])
            ->line(($arabic ? 'مرجع الطلب: ' : 'Application reference: ').$this->reference)
            ->action($arabic ? 'عرض طلب التحقق' : 'View KYC application', $url)
            ->line($arabic
                ? 'سجّل الدخول إلى مساحة الخبير لعرض التفاصيل بأمان.'
                : 'Sign in to the expert workspace to view the details securely.');
    }

    /**
     * @return array{subject: string, line: string}
     */
    private function copy(bool $arabic): array
    {
        return match ($this->status) {
            ExpertKycApplicationStatus::NeedsInformation => $arabic
                ? ['subject' => 'مطلوب تحديث طلب التحقق المهني', 'line' => 'طلب فريق المراجعة معلومات أو مستندات إضافية.']
                : ['subject' => 'Your KYC application needs an update', 'line' => 'The review team requested additional information or documents.'],
            ExpertKycApplicationStatus::Verified => $arabic
                ? ['subject' => 'تم اعتماد طلب التحقق المهني', 'line' => 'تمت مراجعة طلبك واعتماده بنجاح.']
                : ['subject' => 'Your KYC application was approved', 'line' => 'Your application has been reviewed and approved.'],
            ExpertKycApplicationStatus::Rejected => $arabic
                ? ['subject' => 'تم تحديث حالة طلب التحقق المهني', 'line' => 'اكتملت مراجعة الطلب ولم تتم الموافقة عليه في هذه المحاولة.']
                : ['subject' => 'Your KYC application status was updated', 'line' => 'The review is complete and this attempt was not approved.'],
            default => $arabic
                ? ['subject' => 'تحديث طلب التحقق المهني', 'line' => 'تم تحديث حالة طلب التحقق المهني الخاص بك.']
                : ['subject' => 'KYC application update', 'line' => 'Your KYC application status has been updated.'],
        };
    }
}
