<?php

namespace App\Http\Controllers;

use App\Auth\MachinePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class GatewayProbeController extends Controller
{
    #[OA\Get(
        path: '/api/gateway/test',
        operationId: 'gatewayProbe',
        summary: 'Validate an application machine principal',
        security: [['machineBearer' => ['gateway.invoke']]],
        tags: ['Gateway'],
        responses: [
            new OA\Response(response: 200, description: 'Machine principal is authorized'),
            new OA\Response(response: 401, description: 'Bearer token is invalid'),
            new OA\Response(response: 403, description: 'Required scope is missing'),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = MachinePrincipal::fromRequest($request);

        return response()->json([
            'status' => 'authorized',
            'application' => [
                'id' => $principal->application->public_id,
                'api_directory_client_id' => $principal->application->api_directory_client_id,
            ],
            'credential' => [
                'id' => $principal->credential->public_id,
                'name' => $principal->credential->name,
            ],
            'scopes' => $principal->abilities,
        ]);
    }
}
