<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GoogleMeetService;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(private GoogleMeetService $googleMeetService) {}

    public function connect(Request $request)
    {
        $authUrl = $this->googleMeetService->getAuthUrl($request->user());

        return response()->json(['auth_url' => $authUrl]);
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return response()->json(['message' => 'Google authorization was denied'], 400);
        }

        $request->validate(['code' => 'required|string']);

        if ($request->input('state') !== (string) $request->user()->_id) {
            return response()->json(['message' => 'Invalid state parameter'], 403);
        }

        $this->googleMeetService->handleCallback($request->input('code'), $request->user());

        return response()->json(['message' => 'Google account connected successfully']);
    }

    public function status(Request $request)
    {
        $connected = $this->googleMeetService->isConnected($request->user());

        return response()->json(['connected' => $connected]);
    }

    public function disconnect(Request $request)
    {
        $this->googleMeetService->disconnect($request->user());

        return response()->json(['message' => 'Google account disconnected successfully']);
    }
}
