<?php

namespace App\Http\Controllers\api;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ProfileModel;
use App\Models\CountryModel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EmailController;
use Intervention\Image\Facades\Image;
use Storage;

use Illuminate\Support\Facades\Validator;

class HomeController extends EmailController
{
    public function index(Request $req)
    {
        $data = User::with('getUserProfile')->get();
        dd($data);
    }

    public function register(Request $request)
    {
        // $validatedData = $request->validate([
        //     'name' => 'required|string|max:255',
        //     'email' => 'required|string|email|max:255|unique:users',
        //     'password' => 'required|string|min:8',
        // ]);

        // $user = User::create([
        //     'name' => $validatedData['name'],
        //     'email' => $validatedData['email'],
        //     'password' => Hash::make($validatedData['password']),
        // ]);

        // $token = $user->createToken('auth_token')->plainTextToken;

        // return response()->json([
        //     'access_token' => $token,
        //     'token_type' => 'Bearer',
        // ]);
        $user = User::where('email', $request->email)->first();

        if ($user) {
            return response()->json([
                'message' => 'Email already exists',
            ], 401);
        }
        $rules = array(
            'userRole' => ['required', 'integer'],
            'userName' => ['required'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required'],
            'countryId' => ['required'],
            'phone' => ['required'],
            'userImage' => ['required', 'mimes:jpeg,png,jpg,max:20000'],
        );
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        if ($request->userRole > 0) {
            return response()->json([
                'message' => 'Invalid User Role'
            ], 400);
        }

        $user = new User();
        $user->username = $request->userName;
        $user->email = $request->email;
        if ($request->password) {
            $user->password = Hash::make($request->password);
        }
        $user->user_role = $request->userRole;

        $image = $request->userImage;

        /** Make a new filename with extension */
        $filename = time() . rand(1, 30) . '.' . $image->getClientOriginalExtension();

        /**
         * Get real image path using
         * @class Intervention\Image\Facades\Image
         *
         */
        $img = Image::make($image->getRealPath());

        /** Set image dimension to conserve aspect ratio */
        $img->fit(300, 300);

        /** Get image stream to store the image else the tmp file will be stored */
        $img->stream();

        /** Make a new filename with extension */
        $path = Storage::disk('public')->put('public/users/' . $filename, $img);

        /** Update the image index in the data array to update the image path to be stored in database */
        $data['image'] = $filename;

        /** Insert the data in the database */
        $user->avatar = $data['image'];
        $countryName = CountryModel::where('id', $request->countryId)->pluck('name')->first();
        if ($user->save()) {

            $profile = new ProfileModel();
            $profile->user_id = $user->id;
            $profile->full_name = $user->username;
            $profile->country_id = $request->countryId;
            $profile->phone = $request->phone;
            $profile->country = $countryName;
            $profile->save();

            //to shoot an email
//             $this->verifyEmail($user->id);
            if ($request->userRole == 0) {
                return response()->json([
                    'message' => 'Registered Successfully'
                ], 200);
            }
        } else {
            return response()->json([
                'message' => 'Error'
            ], 419);
        }

        //return back()->with('success','Account created successfully!');
    }

    public function login(Request $request)
    {
        //Mz
        $rules = array(
            'email' => 'required|email',
            'password' => 'required',
        );

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        //Mz

        $user = User::where('email', $request->email)->with('getUserProfile')->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid Email'
            ], 401);
        }
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid password'
            ], 401);
        }


        if ($user->status == 1) {
            $token = $user->createToken('auth_token')->plainTextToken;

            if ($user->user_role == 0) {
                $user->role = 'tourist';
            } else if ($user->user_role == 1) {
                $user->role = 'guide';
            } else {
                $user->role = 'admin';
            }

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'data' => $user
            ]);
        } else {
            return response()->json([
                'message' => 'Email not verfied'
            ], 401);
        }

    }
    /**------------Api Controller Auth Forgot-Password Email Shoot Api starts----------------------------------------**/

    public function forgot_password(Request $request)
    {
        /** using validator for api call **/
        $rules = array(
            'email' => 'required|email',
        );
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userfind = User::where('email', $request->email)->first();
        if ($userfind) {
            $userfind->is_reset = 1;
            $userfind->save();
            $details = $userfind;

            //Forgot Password Email Shoot
            $data = $this->forgotpassword($details);
        } else {
            return response()->json([
                'message' => 'Your email is not valid'
            ], 404);
        }
        if ($data) {
            return response()->json([
                'data' => $data,
                'message' => 'An email has been sent to you!'
            ], 200);
        } else {
            return response()->json([
                'message' => 'Please try again later'
            ], 404);
        }
    }
    /**------------Api Controller Auth Forgot-Password Email Shoot Api ends----------------------------------------**/

    /**------------Api Controller Auth Reset-Password Api starts------------------------------------------------**/

    public function reset_password(Request $request)
    {
        /** using validator for api call **/
        $rules = array(
            'userId' => 'required',
            'new_password' => 'min:6|required_with:confirm_password|same:confirm_password',
            'confirm_password' => 'min:6'
        );
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userfind = User::find($request->userId);


        if ($userfind) {
            if (Hash::check($request->new_password, $userfind->password)) {

                return response()->json([
                    'message' => 'Oops! New password cannot be the same as old password.'
                ], 404);

            } else {

                if ($userfind->is_reset === 1) {
                    $userfind->password = Hash::make($request->new_password);
                    $userfind->is_reset = 0;
                    $data = $userfind->save();
                } else {

                    return response()->json([
                        'message' => 'Oops! Your Link Expired'
                    ], 404);
                }
            }
        } else {

            return response()->json([
                'message' => 'Oops! user not found'
            ], 404);
        }

        if ($data) {
            return response()->json([
                'data' => $data,
                'message' => 'Your password updated successfully!'
            ], 200);
        } else {
            return response()->json([
                'message' => 'Oops ! Your updating password request is unsuccessful'
            ], 404);
        }
    }
    /**------------Api Controller Auth Reset-Password Api ends------------------------------------------------**/


    public function loginRequired()
    {
        if (!auth()->user()) {
            return response()->json([
                'message' => 'Login Required'
            ], 401);
        }
    }

    public function logout()
    {
        Auth::logout();
        return response()->json([
            'message' => 'Tokens Revoked'
        ], 200);
//        $v = 0;
//        if(auth()->user()->tokens())
//        {
//            auth()->guard('web')->logout();
//            auth()->user()->tokens()->delete();
//            $v = 1;
//        }
//        else{
//            auth()->guard('web')->logout();
//            $v = 2;
//        }

//        return response()->json([
//            'data' => $v,
//            'message' => 'Tokens Revoked'
//        ], 200);
    }
}
