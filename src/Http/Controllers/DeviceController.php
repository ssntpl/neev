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

        $device = UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_token' => $request->device_token],
            ['device_type' => 'web']
        );

        return response()->json(['status' => 'registered', 'device_id' => $device?->id]);
    }
    
    public function update(Request $request)
    {
        $request->validate([
            'device_id' => 'required',
        ]);

        $device = UserDevice::find($request->device_id);
        $user_id = $request->user()?->id;
        if (!$user_id) {
            $device?->delete();
            return response()->json(['status' => 'unauthorized']);
        }
        $device->updated_at = now();
        $device->save();

        return response()->json(['status' => 'updated']);
    }
    
    public function destroy(Request $request)
    {
        $request->validate([
            'device_id' => 'required',
        ]);

        $device = UserDevice::find($request->device_id);
        $device?->delete();

        return response()->json(['status' => 'deleted']);
    }
}
