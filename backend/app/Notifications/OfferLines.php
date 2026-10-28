<?php

namespace App\Notifications;

use App\Domain\Offers\Offer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

/** Offer list lines shared by the watchlist and digest emails. Offers need chain, variant.product, variant.optionValues. */
trait OfferLines
{
    /** @param  Collection<int, Offer>  $offers */
    protected function addOffers(MailMessage $mail, Collection $offers): MailMessage
    {
        foreach ($offers as $offer) {
            $product = $offer->variant->product;
            $url = rtrim(config('app.frontend_url'), '/')."/products/{$product->slug}?variant=".urlencode($offer->variant->slug);
            $discount = $offer->discount_pct ? ' ('.round((float) $offer->discount_pct).'% off)' : '';
            $mail->line("**[{$offer->variant->label()}]({$url})**: AED {$offer->price} at {$offer->chain->name}{$discount}, until {$offer->valid_to->format('j M')}");
        }

        return $mail;
    }
}
