<?php

namespace App\Http\Controllers;

use App\Support\LiveUpdates;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 58: the live-update channels the signed-in person may listen to, named by LiveUpdates (screens never build them).
 * "enabled" is false when the server is not sending live updates; screens then only poll.
 */
class LiveController extends Controller
{
    use ApiResponse;

    public function channels(Request $request)
    {
        return $this->success(LiveUpdates::channelsFor($request->user()), 'Live channels');
    }
}
