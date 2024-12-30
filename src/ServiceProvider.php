<?php

namespace Teclanltd\MailchimpAero;

use Aero\Common\Facades\Settings;
use Aero\Common\Providers\ModuleServiceProvider;
use Teclanltd\MailchimpAero\Commands\AbandonedCarts;
use Aero\Common\Settings\SettingGroup;
use Illuminate\Console\Scheduling\Schedule;
use Teclanltd\MailchimpAero\Commands\SendProducts;
use Teclanltd\MailchimpAero\Commands\SendOrders;
use Teclanltd\MailchimpAero\Commands\CreateStore;
use Teclanltd\MailchimpAero\Middleware\AddCampaignToSession;
use Aero\Cart\Events\OrderSuccessful;
use Teclanltd\MailchimpAero\Listeners\AddCampaignToOrder;

class ServiceProvider extends ModuleServiceProvider
{
    protected $listen = [
        OrderSuccessful::class => [
            AddCampaignToOrder::class,
        ],
    ];

    public function boot()
    {
        parent::boot();

        app('router')->pushMiddlewareToGroup('store', AddCampaignToSession::class);

        $this->app->booted(function () {
            $schedule = resolve(Schedule::class);
            $schedule->command('teclan:mailchimp:send-orders')->everyFiveMinutes();
            $schedule->command('teclan:mailchimp:products')->hourly();

            if (config('abandoned-orders.mailchimp')) {
                $schedule->command('teclan:mailchimp:send-abandoned-carts')->everyFiveMinutes();
            }
        });

        $this->registerSettings();
    }

    public function registerSettings()
    {
        Settings::group('mailchimp-api', function (SettingGroup $group) {
            $group->boolean('send_all_orders')
                ->hint('Bypass requirement to only send orders assigned to a campaign')
                ->default(false);
            $group->boolean('verbose-logs')->default(false);
        });
    }

    /**
     * Register any module services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/mailchimp.php', 'mailchimp');

        $this->commands([
            SendProducts::class,
            CreateStore::class,
            SendOrders::class,
            AbandonedCarts::class,
        ]);
    }
}
