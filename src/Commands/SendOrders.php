<?php

namespace Teclanltd\MailchimpAero\Commands;

use Teclanltd\MailchimpAero\Actions\MailchimpApiLog;
use Illuminate\Console\Command;
use Aero\Catalog\Models\Variant;
use Aero\Cart\Models\Order;
use Teclanltd\MailchimpAero\Traits\Client;

class SendOrders extends Command
{
    use Client;

    // Expects an expired input like '30' to get orders in the last 30 minutes
    /**
     * @var string
     */
    protected $signature = 'teclan:mailchimp:send-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send successful orders to mailchimp.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->setClient();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->getOrders()->each(function ($order) {
            $this->updateOrCreateCustomer($order);

            if ($this->postOrder($order)) {
                $order->additional('mc_c_notified', now());
            }
        });
    }

    protected function getOrders()
    {
        // Get all orders from the past 24 hours.
        $orders = Order::where('ordered_at', '>', now()->subDay())->get();

        // Reject any order that has already been sent to mailchimp.
        $orders = $orders->reject(function ($order) {
            return $order->hasAdditional('mc_c_notified');
        });

        // Reject if the order is not a result of a campaign.
        if (!setting('mailchimp-api.send_all_orders')) {
            $orders = $orders->filter(function ($order) {
                return $order->hasAdditional('mc_cid');
            });
        }

        return $orders;
    }

    protected function updateOrCreateCustomer($order)
    {
        $optIn = false;

        $paymentMarketingConsent =
            $order->payments->sortBy('created_at')->last()->data['checkbox_marketing_consent'] ?? false;

        $customerMarketingConsent = $order->customer && $order->customer->marketing_consented_at;

        if ($paymentMarketingConsent || $customerMarketingConsent || $order->additional('opted_in')) {
            $optIn = true;
        }

        if (env('MARKETING_TICK_TO_OPT_OUT') || setting('newsletters.tick_to_opt_out')) {
            $optIn = ! $order->additional('opted_in');
        }

        $message = $optIn ? " opted in" : " opted out";
        MailchimpApiLog::info($order->email . $message);

        $this->client->put(
            "ecommerce/stores/{$this->mcInfo['store_id']}/customers/{$order->email}",
            [
                'json' => [
                    'email_address' => $order->email,
                    'opt_in_status' => $optIn,
                    'id' => $order->email
                ]
            ]
        );
    }

    protected function postOrder($order)
    {
        $jsonData = [
            'id' => (string) $order->id,
            'processed_at_foreign' => $order->ordered_at,
            'customer' => [
                'id' => $order->email
            ],
            'currency_code' => $order->currency->code,
            'order_total' => (float) number_format(($order->total + $order->total_tax) / 100, 2),
            'tax_total' => (float) number_format($order->total_tax / 100, 2),
            'shipping_total' => (float) number_format(($order->shipping + $order->shipping_tax) / 100, 2),
            'lines' => $this->getLines($order),
        ];

        MailchimpApiLog::verbose('Sending order', $jsonData);

        if ($order->additional('mc_cid')) {
            $jsonData['campaign_id'] = (string) $order->additional('mc_cid');
        }

        $response = $this->client->post(
            "ecommerce/stores/{$this->mcInfo['store_id']}/orders",
            [
                'json' => $jsonData,
            ]
        );

        $success = $response->getStatusCode() == 200;

        if (! $success) {
            MailchimpApiLog::verbose($response->getBody()->getContents());
        }

        return $success;
    }

    protected function getLines($order)
    {
        return $order->items->map(function ($item) {

            $variant = Variant::find($item->buyable_id);

            return [
                'id' => (string) $item->id,
                'product_id' => (string) $variant->product->id,
                'product_variant_id' => (string) $item->buyable_id,
                'quantity' => (int) $item->quantity,
                'price' => (float) number_format($item->price / 100, 2)
            ];
        });
    }
}
