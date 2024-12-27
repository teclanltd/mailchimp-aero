<?php

namespace Teclanltd\MailchimpAero\Commands;

use Illuminate\Console\Command;
use Aero\Catalog\Models\Variant;
use Aero\Cart\Models\Order;
use Teclanltd\MailchimpAero\Traits\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class AbandonedCarts extends Command
{
    use Client;

    /**
     * @var string
     */
    protected $signature = 'teclan:mailchimp:send-abandoned-carts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send abandoned carts to mailchimp.';

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
        $orders = $this->getOrders();
        $orders->each(function ($order) {
            $checkoutUrl = $this->createRoute($order);
            $this->postCart($order, $checkoutUrl);
            $order->additional('mc_abandoned_order_sent', now());
        });
    }

    protected function getOrders()
    {
        // Get all orders from the past 24 hours.
        $orders = Order::where('created_at', '>', now()->subDay())->get();

        // Reject any order that has already been sent to mailchimp.
        $orders = $orders->reject(function ($order) {
            return $order->hasAdditional('mc_abandoned_order_sent');
        });

        // Only allow Mailchimp abandoned orders
        return $orders->filter(function ($order) {
            return $order->hasAdditional('mc_abandoned_order');
        });

    }

    protected function postCart($order, $checkoutUrl)
    {
        $optIn = false;

        if ($order->additional('opted_in')) {
            $optIn = true;
        }

        try {
            $response = $this->client->post(
                "ecommerce/stores/{$this->mcInfo['store_id']}/carts",
                [
                    'json' => [
                        'id' => (string) $order->id,
                        'customer' => [
                            'id' => $order->email,
                            'email_address' => $order->email,
                            'opt_in_status' => $optIn,
                        ],
                        'currency_code' => $order->currency->code,
                        'order_total' => (float) number_format(($order->total + $order->total_tax) / 100, 2),
                        'tax_total' => (float) number_format($order->total_tax / 100, 2),
                        'shipping_total' => (float) number_format(($order->shipping + $order->shipping_tax) / 100, 2),
                        'lines' => $this->getLines($order),
                        'checkout_url' => $checkoutUrl,
                    ]
                ]
            );
        } catch(\GuzzleHttp\Exception\ClientException $e) {
            Log::error('Error sending abandoned cart to Mailchimp - ' . $e->getMessage());
        }
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

    private function createRoute($order): string
    {
        return Url::route('order.retrieve-mailchimp', [
            'order' => $order,
            'redirect' => 'cart',
        ]);
    }
}
