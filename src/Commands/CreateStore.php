<?php

namespace Teclanltd\MailchimpAero\Commands;

use Elastica\Exception\Connection\GuzzleException;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class CreateStore extends Command
{
    /**
     * @var string
     */
    protected $signature = 'teclan:mailchimp:create-store';

    private $zone;
    private $url;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle() : void
    {
        if(config('mailchimp.audience_id') && config('mailchimp.api_key')) {
            $storeId = config('mailchimp.store_id');
            $config = explode('-', config('mailchimp.api_key'));
            $this->zone = $config[1];
            $this->url = 'https://' . $this->zone . '.api.mailchimp.com/3.0/ecommerce/stores';

            $this->info('Creating a new Mailchimp store under your account!');

            if ($this->confirm('Do you wish to continue?')) {
                $this->addNewStore();
            }
            else {
                exit();
            }

        } else {
            $this->error('You must supply a mailchimp API key and Audience ID in the env file. See the README for instructions.');
        }
    }

    protected function addNewStore() {
        $storeKey = $this->ask('What would you like the store key to be?');
        $storeName = $this->ask('What would you like the store to be called?');
        $storeEmail = $this->ask('What email can Mailchimp contact you on for issues?');
        $storeDomain = $this->ask('What URL is your store located under?');
        $storeCurrency = $this->ask('What currency code do you use on your site?');

        $newStoreData = [
            'id' => $storeKey,
            'list_id' => config('mailchimp.audience_id'),
            'name' => $storeName,
            'domain' => $storeDomain,
            'email_address' => $storeEmail,
            'currency_code' => $storeCurrency
        ];

        $this->info('Creating new store on Mailchimp...');

        $client = new Client();

        try {
            $response = $client->request('POST', 'https://' . $this->zone . '.api.mailchimp.com/3.0/ecommerce/stores',
                [
                    'auth'  => ['app', config('mailchimp.api_key')],
                    'json' => $newStoreData
                ]
            );

            $this->info('Store created!');
            $this->warn('REMEMBER TO ADD MAILCHIMP_STORE_ID=' . $storeKey . ' TO YOUR ENVIRONMENT FILE!!!');
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            Log::error('Error in Mailchimp module CreateStore command - ' . $e->getMessage());
        }
    }

}