<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GoogleMeetService;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(private GoogleMeetService $googleMeetService) {}

    /**
     * Initiate Google OAuth connection
     *
     * Returns a Google OAuth URL to redirect the user to for granting calendar access.
     *
     * @response 200 { "auth_url": "https://accounts.google.com/o/oauth2/v2/auth?..." }
     */
    public function connect(Request $request)
    {
        $authUrl = $this->googleMeetService->getAuthUrl($request->user());

        return response()->json(['auth_url' => $authUrl]);
    }

    /**
     * Handle Google OAuth callback
     *
     * Exchanges the authorization code for tokens and stores them for the authenticated user.
     *
     * @queryParam code string required The authorization code from Google. Example: 4/0AX4XfWg...
     * @queryParam state string required User ID for CSRF validation. Example: 664f1a2b3c4d5e6f7a8b9c0a
     *
     * @response 200 { "message": "Google account connected successfully" }
     * @response 400 { "message": "Google authorization was denied" }
     * @response 403 { "message": "Invalid state parameter" }
     */
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

    /**
     * Check Google integration status
     *
     * Returns whether the authenticated user has a valid Google account connection.
     *
     * @response 200 { "connected": true }
     */
    public function status(Request $request)
    {
        $connected = $this->googleMeetService->isConnected($request->user());

        return response()->json(['connected' => $connected]);
    }

    /**
     * Disconnect Google account
     *
     * Removes the stored Google OAuth tokens for the authenticated user.
     *
     * @response 200 { "message": "Google account disconnected successfully" }
     */
    public function disconnect(Request $request)
    {
        $this->googleMeetService->disconnect($request->user());

        return response()->json(['message' => 'Google account disconnected successfully']);
    }
}
