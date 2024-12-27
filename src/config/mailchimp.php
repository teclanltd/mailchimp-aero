<?php

return [
    'audience_id' => env('MAILCHIMP_AUDIENCE_ID'),
    'store_id' => env('MAILCHIMP_STORE_ID'),
    'api_key' => env('MAILCHIMP_APIKEY'),
    'image_size' => env('MAILCHIMP_IMAGE_SIZE', '500x500:contain'),
];