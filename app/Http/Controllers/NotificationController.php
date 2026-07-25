<?php

namespace App\Http\Controllers;

class NotificationController extends Controller
{
    public function markAllRead()
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back();
    }
}