<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Shop;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class authConttroler extends Controller
{
public function register(Request $request){
    $data=$request->validate([
        'shop_name'=>'required',
        'name'=>'required',
        'email'=>'required|email',
        'password' => 'required|string|min:8|confirmed',
     ]);


     $shop=Shop::create([
        'shop_name' => $data['shop_name'],
        'expires_at' => now()->addYear(),
     ]);

     $user=User::create([
        'name'=>$data['name'],
        'password' => Hash::make($data['password']),
        'email' => $data['email'],
        'shop_id'=>$shop->id,

     ]);


     $token=$user->createToken('token')->plainTextToken;

         return response()->json([
        'message' => 'Registered successfully',
        'user' => $user,
        'token' => $token,
    ], 201);

}



public function login(Request $request)
{
    $data = $request->validate([
        'login' => 'required',       // can be name OR email
        'password' => 'required',
    ]);

    // Detect: is it an email or a name?
    $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

    if (!Auth::attempt([$field => $data['login'], 'password' => $data['password']])) {
        return response()->json([
            'msg' => 'Wrong ' . $field . ' or password'
        ], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token,
    ]);
}



public function addUser(Request $request)
{
    $data = $request->validate([
        'shop_id' => 'required|exists:shops,id',
        'name' => 'required|string',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
        'role' => 'nullable|in:user,admin',
    ]);

    $user = User::create([
        'shop_id' => $data['shop_id'],
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => $data['role'] ?? 'user',
    ]);

    return response()->json([
        'message' => 'User added to shop successfully',
        'user' => $user,
    ], 201);
}


public function logout(Request $request)
{
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'message' => 'Logged out successfully'
    ]);
}
}
