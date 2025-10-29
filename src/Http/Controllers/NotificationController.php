<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;

class NotificationController extends Controller
{
    public function markAsRead(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
        $notification = $user->unreadNotifications()->find($request->id);
        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found',
            ]);
        }

        $notification->markAsRead();
        
        return response()->json([
            'success' => true,
        ]);
    }
    
    public function markAllAsRead(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $notifications = $user->unreadNotifications();
        if (!$notifications) {
            return response()->json([
                'status' => false,
                'message' => 'Notifications not found',
            ]);
        }

        $notifications->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
        ]);
    }
    
    public function destroy(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }
        
        $notification = $user->notifications()->find($request->id);
        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found',
            ]);
        }
        
        $notification->delete();
        
        return response()->json([
            'success' => true,
        ]);
    }
}