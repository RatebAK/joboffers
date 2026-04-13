<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
// use Illuminate\Auth\Events\Registered;
// use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    //TODO fix the middleware
    // public function __construct()
    // {
    //     $this->middleware('auth:api', ['except' => ['login', 'register'/*, 'verifyEmail', 'resendVerificationEmail'*/]]);
    // }

    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|min:2|max:100',
            'email' => [
                'required',
                'string',
                'email:rfc,dns', // Improved email validation to check for a valid format and DNS record
                'max:100',
                'unique:users,email' // Explicitly specify the column name
            ],
            'password' => [
                'required',
                'string',
                'min:8', // Increase minimum password length for better security
                'confirmed',
                'regex:/[a-z]/', // Require at least one lowercase letter
                'regex:/[A-Z]/', // Require at least one uppercase letter
                'regex:/[0-9]/', // Require at least one digit
                'regex:/[@$!%*#?&]/' // Require at least one special character
            ],
        ];
        
        $validator = Validator::make($request->all(), $rules);
        

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Prepare data for creation
        $data = $validator->validated();

        // --- NEW: Set default role to 'job_seeker' ---
        $data['roles'] = ['job_seeker'];
        // --- END NEW ---

        // Hash password
        $data['password'] = bcrypt($request->password);
        
        $user = User::create($data);

        // Trigger email verification event (commented out)
        // event(new Registered($user));

        // Generate token for the registered user
        $token = auth()->login($user);

        return response()->json([
            'message' => 'User successfully registered',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if (!$token = auth()->attempt($validator->validated())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if email is verified (commented out)
        // if (!auth()->user()->hasVerifiedEmail()) {
        //     auth()->logout();
        //     return response()->json(['error' => 'Email not verified'], 403);
        // }

        return $this->createNewToken($token);
    }

    public function profile()
    {
        return response()->json(auth()->user());
    }

    public function logout()
    {
        auth()->logout();
        return response()->json(['message' => 'User successfully signed out']);
    }

    public function refresh()
    {
        return $this->createNewToken(auth()->refresh());
    }

    // Email verification method (commented out)
    /*
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::find($id);
        
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email successfully verified']);
    }
    */

    // Resend verification email method (commented out)
    /*
    public function resendVerificationEmail(Request $request)
    {
        $user = $request->user();
        
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent']);
    }
    */

    protected function createNewToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }
}