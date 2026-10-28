<?php

namespace App\Notifications;

use App\Domain\Offers\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** "Items you're watching are on offer", sent once per person per published brochure. */
class WatchlistOffers extends Notification implements ShouldQueue
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
        $count = $this->offers->count();
        $mail = (new MailMessage)
            ->subject($count === 1 ? "On offer now: {$this->offers->first()->variant->product->name}" : "{$count} items you're watching are on offer")
            ->greeting('Good news!')
            ->line($count === 1 ? 'Something you are watching is on offer:' : 'Some things you are watching are on offer:');

        return $this->addOffers($mail, $this->offers)
            ->action('See all deals', rtrim(config('app.frontend_url'), '/').'/plan')
            ->line('You get this because you liked these products or set an alert. Manage alerts on your account page.');
    }
}
