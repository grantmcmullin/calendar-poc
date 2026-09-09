<?php

namespace App\Http\Controllers\Demo;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Domain\Webhooks\WebhookEndpoint;
use App\Domain\Webhooks\WebhookSignature;

class WebhookSinkController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $verified = WebhookEndpoint::where('active', true)->get()->contains(
            fn (WebhookEndpoint $endpoint): bool => WebhookSignature::verify(
                $endpoint->secret,
                $request->getContent(),
                (string) $request->header('Calendar-Service-Signature'),
            ),
        );

        abort_unless($verified, 400, 'Invalid webhook signature.');

        return response()->noContent();
    }
}
