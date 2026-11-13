<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTextMessagesRequest;
use App\Services\Sms\TextMessagePreference;
use App\Traits\ApiResponse;

/** Batch 67: the signed-in person turns their text messages on or off (Account page, every portal). */
class TextMessagesController extends Controller
{
    use ApiResponse;

    public function __construct(private TextMessagePreference $preference) {}

    public function update(UpdateTextMessagesRequest $request)
    {
        $enabled = $this->preference->set($request->user(), (bool) $request->validated('enabled'));

        return $this->success(['sms_opt_in' => $enabled], $enabled ? 'Text messages are on.' : 'Text messages are off.');
    }
}
