<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'BC AI Gateway API',
    description: 'OpenAI-compatible gateway invocation API for the BC AI Gateway.',
)]
#[OA\Server(
    url: '/api/gateway',
    description: 'Gateway invocation API base path.',
)]
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
