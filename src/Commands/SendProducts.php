<?php

namespace Teclanltd\MailchimpAero\Commands;

use Aero\Catalog\Models\Variant;
use Teclanltd\MailchimpAero\Actions\MailchimpApiLog;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Aero\Catalog\Models\Product;

class SendProducts extends Command
{
    //Expects --allProducts option of either true or false. True sends all active products to MC. False only sends products that have been updated in the last 2 hours to MC (Default)
    /**
     * @var string
     */
    protected $signature = 'teclan:mailchimp:products {--allProducts=false}';

    private $zone;
    private $url;

    private $sent = 0;
    private $failed = 0;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle() : void
    {
        if(config('mailchimp.audience_id') && config('mailchimp.api_key')) {
            $config = explode('-', config('mailchimp.api_key'));
            $this->zone = $config[1];
            $this->url = 'https://' . $this->zone . '.api.mailchimp.com/3.0/ecommerce/stores';

            if(!config('mailchimp.store_id')) {
                $this->info('No Store ID found!');
                $this->info('Run "mailchimp:create-store" to set up a new store');
                exit();

            } else {
                if ($this->option('allProducts') == 'true') {
                    $this->sendProductsToMailchimp(true);
                } else {
                    $this->sendProductsToMailchimp(false);
                }

                MailchimpApiLog::verbose("Products sent - {$this->sent}");
                MailchimpApiLog::verbose("Products failed - {$this->failed}");
            }
        } else {
            $this->error('You must supply a mailchimp API key and Audience ID in the env file. See the README for instructions.');
        }
    }

    protected function sendProductsToMailchimp($allProducts = false) {
        $productData = [];
        $self = $this;

        if ($allProducts) {
            $productCount = Product::where('active', '1')->count();
            MailchimpApiLog::verbose("Found {$productCount} active products");

            Product::where('active', '1')->get()
            ->each(static function (Product $product) use ($productData, $self) {
                $self->deleteProduct($product);
                $productData = $self->getProductData($product, $productData);
                $self->postProduct($product, $productData);
            });
        } else {
            Product::where(static function ($query) {
                $query->where('products.updated_at', '>=', now()->subHours(2))
                    ->orWhereHas('variants', static function ($query) {
                        $query->where('variants.updated_at', '>=', now()->subHours(2));
                    })
                    ->orWhereHas('prices', static function ($query) {
                        $query->where('prices.updated_at', '>=', now()->subHours(2));
                    });
            })->get()
            ->each(static function (Product $product) use ($productData, $self) {
                $self->deleteProduct($product);
                $productData = $self->getProductData($product, $productData);
                $self->postProduct($product, $productData);
            });
        }
    }

    protected function deleteProduct($product) {
        $client = new Client();

        try {
            $response = $client->request('DELETE', $this->url . '/' .  config('mailchimp.store_id') . '/products/'.$product->id,
                [
                    'auth'  => ['app', config('mailchimp.api_key')],
                ]
            );

            $success =  $response->getStatusCode() == 204;

            if (! $success) {
                MailchimpApiLog::verbose('Failed deleting product ' . $product->id);
                MailchimpApiLog::verbose($response->getBody()->getContents());
            }
        } catch (\Exception $e) {
            $response = null;
            MailchimpApiLog::error('Error in Mailchimp module Products command, when deleting products- ' . $e->getMessage());
        }

        return $response;
    }

    protected function postProduct($product, $productData) {
        $client = new Client();

        MailchimpApiLog::verbose('postProduct', $productData);

        try {
            $response = $client->request('POST', $this->url  . '/' . config('mailchimp.store_id') . '/products',
                [
                    'auth'  => ['app', config('mailchimp.api_key')],
                    'json' => ($productData)
                ]
            );

            $success =  $response->getStatusCode() == 200;

            if (! $success) {
                $this->error('Failed adding product ' . $product->id);
                MailchimpApiLog::verbose('Failed adding product ' . $product->id);
                MailchimpApiLog::verbose($response->getBody()->getContents());
                return;
            }

            $this->info('Added product ' . $product->id);
            $this->sent++;
        } catch(\GuzzleHttp\Exception\ClientException $e) {
            MailchimpApiLog::error('Error in Mailchimp module Products command, when posting products - ' . $e->getMessage());
            $this->patchProduct($product, $productData);
        }
    }

    protected function patchProduct($product, $productData) {
        MailchimpApiLog::verbose('patchProduct', $productData);

        try {
            $client = new Client();

            $response =
                $client->request('PATCH',
                    $this->url  . '/' .  config('mailchimp.store_id') . '/products/'.$product->id, [
                        'auth' => ['app', config('mailchimp.api_key')], 'json' => ($productData)
                    ]);

            $success =  $response->getStatusCode() == 200;

            if (! $success) {
                $this->error('Failed updating product ' . $product->id);
                MailchimpApiLog::verbose('Failed updating product ' . $product->id);
                MailchimpApiLog::verbose($response->getBody()->getContents());
                return;
            }

            $this->info('Updated product ' . $product->id);
            $this->sent++;
        } catch(\GuzzleHttp\Exception\ClientException $e) {
            $this->failed++;
            MailchimpApiLog::error('Error in Mailchimp module Products command, when patching products - ' . $e->getMessage());
        }
    }

    protected function getProductdata($product, $productData) {
        $variantData = [];

        $product->variants->map(static function (Variant $variant) use (&$variantData) {
            if($variant->prices()->first()) {
                $title = "";
                if (!empty($variant->name)) {
                    $title = $variant->name;
                } else {
                    $title = $variant->product->name;
                }
                $variantData[] = [
                    'id' => "$variant->id",
                    'title' => $title,
                    'sku' => $variant->sku,
                    'price' => ($variant->prices()->first()->value_inc / 100),
                    'inventory_quantity' => $variant->stock_level ?? 0,
                ];
            }
        });

        $image = $product->images->first()->file ?? null;

        $productData += [
            'id' => "$product->id",
            'title' => $product->name,
            'url' => env('APP_URL').$product->url,
            'description' => $product->description,
            'image_url' => $image ? image_factory(500, 500, $image)->__toString() : null,
            'variants' => $variantData,
            'published_at_foreign' => date('Y-m-d H:i:s', strtotime($product->created_at))
        ];

        return $productData;
    }

}
