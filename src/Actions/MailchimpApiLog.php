<?php

namespace Teclanltd\MailchimpAero\Actions;

use Techquity\AeroLogs\AeroLog;

class MailchimpApiLog extends AeroLog
{
    protected static ?string $key = '[Mailchimp API]';

    protected static function useVerbose(): bool
    {
        return setting('mailchimp-api.verbose-logs');
    }
}