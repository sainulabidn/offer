<?php

namespace App\Notifications;

use App\Domain\Offers\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class WeeklyDigestMail extends Notification implements ShouldQueue
{
    use OfferLines, Queueable;

    /** @param  Collection<int, Offer>  $offers */
    public function __construct(public readonly Collection $offers) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('This week\'s deals on products you like')
            ->line('Here is what your liked products cost this week:');

        return $this->addOffers($mail, $this->offers)
            ->action('Compare your shopping plan', rtrim(config('app.frontend_url'), '/').'/plan');
    }
}
