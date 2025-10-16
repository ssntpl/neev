<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\UserDevice;

class DeviceController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'device_token' => 'required|string',
        ]);

        $user = $request->user();

        UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_token' => $request->device_token],
            ['device_type' => 'web']
        );

        return response()->json(['status' => 'registered']);
    }
}
