<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\Counters;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Anonymous counts from the website. The route has no session or sign-in, so nothing here can link
 * a count to a person, and every visitor is counted the same way.
 */
class EventController extends Controller
{
    public function __invoke(Request $request, Counters $counters): Response
    {
        // Browsers send these with sendBeacon as text/plain, which validate() can't see; json() reads any body.
        $data = Validator::make($request->json()->all(), [
            'region' => ['nullable', 'integer', 'min:1'],
            'events' => ['required', 'array', 'max:50'],
            'events.*.kind' => ['required', Rule::in(Counters::KINDS)],
            'events.*.id' => ['required', 'integer', 'min:1'],
            'events.*.type' => ['required', Rule::in(array_keys(Counters::TYPES))],
        ])->validate();

        $counters->add($data['events'], $data['region'] ?? 0);

        return response()->noContent();
    }
}
