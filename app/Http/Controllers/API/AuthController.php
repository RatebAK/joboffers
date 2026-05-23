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

    /**
     * Register
     *
     * Create a new user account. Returns a JWT token on success.
     *
     * @unauthenticated
     * @bodyParam name string required Display name. Min 2, max 100 chars. Example: Jane Smith
     * @bodyParam email string required Valid email address. Example: jane@example.com
     * @bodyParam password string required Min 8 chars, must include uppercase, lowercase, digit, and special character. Example: Secret@123
     * @bodyParam password_confirmation string required Must match password. Example: Secret@123
     * @bodyParam role string Role to assign. Defaults to `employee`. Example: employee
     *
     * @response 201 {
     *   "message": "User successfully registered",
     *   "user": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Jane Smith", "email": "jane@example.com", "roles": ["employee"] },
     *   "access_token": "eyJ...",
     *   "token_type": "bearer",
     *   "expires_in": 3600
     * }
     * @response 422 { "email": ["The email has already been taken."] }
     */
    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|min:2|max:100',
            'email' => [
                'required',
                'string',
                app()->environment('testing') ? 'email:rfc' : 'email:rfc,dns', // dns skipped in test env
                //'email:rfc,dns', THIS IS THE ORIGINAL LINE OF CODE, dns got deleted (THEN I DID PUT IT BACK AGAIN) because it might be breaking Laravel Cloud Free Plan EDITED BY RATEB
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
            'role' => 'nullable|string|in:admin,employer,employee', // Role validation
        ];
        
        //$validator = Validator::make($request->all(), $rules);
        $validator = Validator::make($request->post(), $rules); //THIS WAS CHANGED TO POST TO PREVENT 'PARAM' SENDING DATA VIA URL FOR LOGIN OR REGISTER EDITED BY RATEB
        

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Prepare data for creation
        $data = $validator->validated();

        // Assign specified role or default to 'employee'
        $role = $data['role'] ?? 'employee';
        $data['roles'] = [$role];
        
        // Remove 'role' from data as it's not a database field
        unset($data['role']);

        // Hash password - use fallback if bcrypt not available
        try {
            $data['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
        } catch (\Exception $e) {
            // Fallback for testing environments where bcrypt is not available
            $data['password'] = hash('sha256', $request->password . 'salt');
        }
        
        $user = User::create($data);


        // TEST BY RATEB
        // Automatically create a pending Employer record if the registered role is employer
        if ($role === 'employer') {
            \App\Models\Employer::create([
                '_id'     => (string) $user->_id, // Keeps _id and user_id identical for easier matching
                'user_id' => (string) $user->_id,
                'status'  => \App\Models\Employer::STATUS_PENDING,
            ]);
        }
        // ---------------------------
        
        // Trigger email verification event (commented out)
        // event(new Registered($user));

        // Generate token for the registered user
        $token = auth('api')->login($user);
        // $token = auth()->login($user); THIS IS THE ORIGINAL LINE OF CODE BEFORE ADDING 'api' EDITED BY RATEB 

        // 'expires_in' => auth()->factory()->getTTL() * 60 SAME HERE WITH THIS ONE EDITED BY RATEB
        return response()->json([
            'message' => 'User successfully registered',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ], 201);
    }

    /**
     * Login
     *
     * Authenticate with email and password. Returns a JWT bearer token.
     *
     * @unauthenticated
     * @bodyParam email string required Example: jane@example.com
     * @bodyParam password string required Example: Secret@123
     *
     * @response 200 {
     *   "access_token": "eyJ...",
     *   "token_type": "bearer",
     *   "expires_in": 3600,
     *   "user": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Jane Smith", "email": "jane@example.com", "roles": ["employee"] }
     * }
     * @response 401 { "error": "Unauthorized", "message": "Invalid credentials" }
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->post(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]); //THIS WAS CHANGED TO POST TO PREVENT 'PARAM' SENDING DATA VIA URL FOR LOGIN OR REGISTER EDITED BY RATEB
        //$validator = Validator::make($request->all(), [
        //    'email' => 'required|email',
        //    'password' => 'required|string|min:6',
        //]); 

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $credentials = $validator->validated();
        
        // Try to find the user first
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid credentials'
            ], 401);
        }
        
        // Try standard bcrypt authentication first
        try {
            if ($token = auth('api')->attempt($credentials)) { //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api' EDITED BY RATEB 
                // return $this->createNewToken(auth('api')->getToken()); //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api' EDITED BY RATEB 
                // THE TOKEN ISN'T STORED IN THE DATABASE SO WE GENERATE A NEW TOKEN PER LOGIN EDITED BY RATEB 
                return $this->createNewToken($token);
            }
        } catch (\Exception $e) {
            // If bcrypt fails, try fallback authentication
        }
        
        // Fallback authentication for testing environments
        $fallbackHash = hash('sha256', $credentials['password'] . 'salt');
        if ($user->password === $fallbackHash) {
            $token = auth('api')->login($user); //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api' EDITED BY RATEB 
            return $this->createNewToken($token);
        }

        return response()->json([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ], 401);
    }

    /**
     * Get profile
     *
     * Returns the authenticated user's account details.
     *
     * @response 200 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "name": "Jane Smith",
     *   "email": "jane@example.com",
     *   "roles": ["employee"],
     *   "is_employer": false
     * }
     */
    public function profile()
    {
        return response()->json(auth('api')->user()); //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api' EDITED BY RATEB 
    }

    /**
     * Logout
     *
     * Invalidates the current JWT token.
     *
     * @response 200 { "message": "User successfully signed out" }
     */
    public function logout()
    {
        auth('api')->logout();
        return response()->json([
            'message' => 'User successfully signed out'
        ]);
    }

    /**
     * Refresh token
     *
     * Issues a new JWT token using the current (still-valid) token.
     *
     * @response 200 {
     *   "access_token": "eyJ...",
     *   "token_type": "bearer",
     *   "expires_in": 3600,
     *   "user": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Jane Smith", "email": "jane@example.com" }
     * }
     */
    public function refresh()
    {
        //FIXING THE NULL USER WHEN REFRESHING EDITED BY RATEB
        // Grab the user first while the current token is still valid
        $user = auth('api')->user(); 
        // Refresh the token
        $newToken = auth('api')->refresh(); 
        // Pass BOTH to your token generator
        return $this->createNewToken($newToken, $user);
        
        // return $this->createNewToken(auth('api')->refresh()); //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api' EDITED BY RATEB 
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
            'expires_in' => auth('api')->factory()->getTTL() * 60, //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api'
            // 'user' => auth('api')->user() //THE ORIGINAL PIECE OF CODE DOESN"T HAVE 'api'
            'user' => $user ?? auth('api')->user() //FIXING THE NULL USER WHEN REFRESHING EDITED BY RATEB
        ]);
    }
}
