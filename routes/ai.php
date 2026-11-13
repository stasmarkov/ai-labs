<?php

use App\Mcp\Servers\ListingsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('listings', ListingsServer::class);
