<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;

class ManagementController extends Controller
{
    public function profile(Request $request)
    {
        if (!in_array($request->user()->email,config('neev.app_owner'))) {
            return back()->withErrors('message', 'not found');
        }
        return view('neev::management.profile', [
            'user' => User::find($request->user()->id),
        ]);
    }
}
