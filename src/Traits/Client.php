<?php

namespace Teclanltd\MailchimpAero\Traits;

use GuzzleHttp\Client as Guzzle;

trait Client
{
    protected $client;

    protected $mcInfo = [];

    /**
     * Set the guzzle client.
     *
     * @return void
     */
    protected function setClient()
    {
        $this->mcInfo = [
            'store_id' => config('mailchimp.store_id'),
            'api_key' => config('mailchimp.api_key')
        ];

        $this->client = new Guzzle([
            'base_uri' => 'https://' . $this->getZone() . '.api.mailchimp.com/3.0/',
            'http_errors' => false,
            'auth' => [
                'tqt',
                $this->mcInfo['api_key']
            ],
        ]);
    }

    /**
     * Get the server zone from the api key.
     *
     * @return string
     */
    private function getZone(): string
    {
        $api = explode('-', $this->mcInfo['api_key']);

        return isset($api[1]) ? $api[1] : '';
    }

    /**
     * Check the connection is valid.
     *
     * @return bool
     */
    public function validClient()
    {
        return $this->client->get('ping')->getStatusCode() === 200;
    }

    public function json($response)
    {
        return json_decode($response->getBody()->getContents());
    }
}
