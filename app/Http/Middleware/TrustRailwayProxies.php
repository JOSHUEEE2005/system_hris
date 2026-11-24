<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

class TrustRailwayProxies extends TrustProxies
{
    protected $proxies = '*';
}
