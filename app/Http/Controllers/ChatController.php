<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // Mengambil daftar staf (untuk sisi user)
    public function getStaffList() {
        $staff = User::where('usertype', 'admin')->get();
        return response()->json($staff);
    }

    // Mengambil histori pesan dengan user tertentu
    public function getMessages($userId) {
        $myId = auth()->id();
        $messages = Message::where(function($q) use ($myId, $userId) {
            $q->where('sender_id', $myId)->where('receiver_id', $userId);
        })->orWhere(function($q) use ($myId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $myId);
        })->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }

    // Menyimpan dan mem-broadcast pesan
    public function sendMessage(Request $request) {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string'
        ]);

        $message = Message::create([
            'sender_id' => auth()->id(),
            'receiver_id' => $request->receiver_id,
            'message' => $request->message
        ]);

        // Trigger Event Pusher
        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message);
    }
}
