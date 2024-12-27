<?php

namespace Teclanltd\MailchimpAero\Listeners;

use Aero\Cart\Events\OrderSuccessful;
use Illuminate\Contracts\Queue\ShouldQueue;

class AddCampaignToOrder implements ShouldQueue
{
    public function handle(OrderSuccessful $event)
    {
        if (session()->has('mc_cid')) {
            $event->order->additional('mc_cid', session('mc_cid'));
        }
    }
}