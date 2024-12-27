<?php

namespace Teclanltd\MailchimpAero\Middleware;

use Closure;

class AddCampaignToSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->has('mc_cid')) {
            $request->session()->put('mc_cid', $request->input('mc_cid'));
        }

        return $next($request);
    }
}