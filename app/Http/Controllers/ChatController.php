<?php

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf (untuk sisi user)
//     public function getStaffList() {
//         $staff = User::where('usertype', 'admin')->get();
//         return response()->json($staff);
//     }

//     // Mengambil histori pesan dengan user tertentu
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $messages = Message::where(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // Menyimpan dan mem-broadcast pesan
//     public function sendMessage(Request $request) {
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $request->message
//         ]);

//         // Trigger Event Pusher
//         broadcast(new MessageSent($message))->toOthers();

//         return response()->json($message);
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product; // Untuk konteks AI
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Google\Client as GoogleClient;
// use Google\Service\GenerativeLanguage;
// use Google\Service\GenerativeLanguage\GenerateContentRequest;
// use Google\Service\GenerativeLanguage\Content;
// use Google\Service\GenerativeLanguage\Part;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf (ditambah AI Assistant)
//     public function getStaffList() {
//         $staff = User::where('usertype', 'admin')->get()->toArray();
        
//         // Inject Gycora AI Assistant di urutan paling atas
//         $aiAssistant = [
//             'id' => 0, // ID khusus untuk AI
//             'first_name' => 'Gycora',
//             'last_name' => 'AI Assistant',
//             'usertype' => 'Bot 24/7',
//             'profile_image' => null,
//         ];
        
//         array_unshift($staff, $aiAssistant);

//         return response()->json($staff);
//     }

//     // Mengambil histori pesan
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $messages = Message::where(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // Menyimpan dan mem-broadcast pesan (Dengan logika AI)
//     public function sendMessage(Request $request) {
//         $request->validate([
//             'receiver_id' => 'required|numeric', // Bisa ID admin, bisa 0 untuk AI
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiverId = (int) $request->receiver_id;

//         // 1. Simpan pesan pengguna ke database
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiverId,
//             'message' => $request->message
//         ]);

//         // Jika pesannya dikirim ke Admin manusia biasa
//         if ($receiverId !== 0) {
//             broadcast(new MessageSent($userMessage))->toOthers();
//             return response()->json($userMessage);
//         }

//         // ==========================================================
//         // JIKA PESAN DIKIRIM KE AI (receiver_id == 0)
//         // ==========================================================
        
//         // Panggil Gemini secara asinkronus (atau synchronous tapi cepat)
//         $aiResponseText = $this->generateGeminiResponse($request->message);

//         // 2. Simpan balasan AI ke database (Seolah-olah AI yang membalas)
//         $aiMessage = Message::create([
//             'sender_id' => 0, // ID pengirim adalah AI
//             'receiver_id' => $myId,
//             'message' => $aiResponseText
//         ]);

//         // 3. Broadcast balasan AI ke pengguna
//         broadcast(new MessageSent($aiMessage))->toOthers();

//         // Kembalikan pesan user agar frontend bisa me-rendernya
//         return response()->json($userMessage);
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             // Tarik sedikit data dari database untuk konteks AI
//             // Misalnya: Produk aktif, harga, dan ketersediaan stok
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description')
//                 ->take(10) // Batasi agar token tidak kepenuhan
//                 ->get();
            
//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             // Atur Persona/Sistem Instruksi untuk AI
//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional. Jawablah pertanyaan pengguna berdasarkan data produk berikut ini. Jika pengguna bertanya hal di luar produk Gycora, tolak dengan halus.\n\n" . $dbContext;

//             // Membangun request ke Google Gemini API (REST HTTP murni untuk kecepatan)
//             $apiKey = env('GEMINI_API_KEY');
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

//             $payload = [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemInstruction]]
//                 ],
//                 'contents' => [
//                     ['role' => 'user', 'parts' => [['text' => $userText]]]
//                 ]
//             ];

//             $response = Http::withHeaders(['Content-Type' => 'application/json'])
//                 ->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf, saya agak bingung. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf, koneksi saya sedang bermasalah. Mohon hubungi admin manusia kami.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf, sistem AI sedang offline.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product; 
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf (ditambah AI Assistant)
//     public function getStaffList() {
//         $staff = User::where('usertype', 'admin')->get()->toArray();
        
//         // Inject Gycora AI Assistant di urutan paling atas
//         $aiAssistant = [
//             'id' => 0, // ID khusus untuk AI
//             'first_name' => 'Gycora',
//             'last_name' => 'AI Assistant',
//             'usertype' => 'Bot 24/7',
//             'profile_image' => null,
//         ];
        
//         array_unshift($staff, $aiAssistant);

//         return response()->json($staff);
//     }

//     // Mengambil histori pesan
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $messages = Message::where(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // Menyimpan pesan
//     public function sendMessage(Request $request) {
//         $request->validate([
//             'receiver_id' => 'required|numeric', 
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiverId = (int) $request->receiver_id;

//         // 1. Simpan pesan pengguna ke database
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiverId,
//             'message' => $request->message
//         ]);

//         // JIKA KE MANUSIA (Admin)
//         if ($receiverId !== 0) {
//             broadcast(new MessageSent($userMessage))->toOthers();
//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA KE AI (receiver_id == 0)
//         // ==========================================================
        
//         // Panggil Gemini (Synchronous)
//         $aiResponseText = $this->generateGeminiResponse($request->message);

//         // 2. Simpan balasan AI ke database
//         $aiMessage = Message::create([
//             'sender_id' => 0, 
//             'receiver_id' => $myId,
//             'message' => $aiResponseText
//         ]);

//         // 3. Broadcast pesan AI (TANPA toOthers() karena kita mau user saat ini juga menerima notifikasinya jika mereka buka 2 tab, 
//         // walau sebenarnya kita langsung mereturn ai_message di bawah)
//         broadcast(new MessageSent($aiMessage));

//         // KEMBALIKAN KEDUA PESAN agar Frontend bisa merender langsung tanpa menunggu Pusher
//         return response()->json([
//             'status' => 'success',
//             'user_message' => $userMessage,
//             'ai_message' => $aiMessage // Mengirim balasan AI langsung
//         ]);
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description')
//                 ->take(15) 
//                 ->get();
            
//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional (gunakan gaya bahasa 'halo', 'kak', dll). Jawablah pertanyaan pengguna berdasarkan data produk berikut ini. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora atau pertanyaan tidak jelas, tolak dengan sangat halus atau tawarkan untuk dihubungkan ke admin manusia.\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            
//             if (!$apiKey) {
//                 return "Maaf, kunci API AI belum dikonfigurasi oleh administrator.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

//             $payload = [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemInstruction]]
//                 ],
//                 'contents' => [
//                     ['role' => 'user', 'parts' => [['text' => $userText]]]
//                 ]
//             ];

//             $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf, saya sedang gagal memproses jawaban Anda. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf, koneksi otak AI saya sedang bermasalah. Mohon hubungi admin manusia kami.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf, sistem AI sedang offline saat ini.";
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Product; 
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    // Mengambil daftar staf (ditambah AI Assistant)
    public function getStaffList() {
        $staff = User::where('usertype', 'admin')->get()->toArray();
        
        // Inject Gycora AI Assistant di urutan paling atas
        $aiAssistant = [
            'id' => 0, // ID khusus untuk AI
            'first_name' => 'Gycora',
            'last_name' => 'AI Assistant',
            'usertype' => 'Bot 24/7',
            'profile_image' => null,
        ];
        
        array_unshift($staff, $aiAssistant);

        return response()->json($staff);
    }

    // Mengambil histori pesan
    public function getMessages($userId) {
        $userId = (int) $userId;

        // Mencegah error database: Jika chat ke AI (0), return array kosong (history tidak disimpan di DB)
        if ($userId === 0) {
            return response()->json([]);
        }

        $myId = auth()->id();
        $messages = Message::where(function($q) use ($myId, $userId) {
            $q->where('sender_id', $myId)->where('receiver_id', $userId);
        })->orWhere(function($q) use ($myId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $myId);
        })->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }

    // Menyimpan pesan
    public function sendMessage(Request $request) {
        $request->validate([
            'receiver_id' => 'required|numeric', 
            'message' => 'required|string'
        ]);

        $myId = auth()->id();
        $receiverId = (int) $request->receiver_id;

        // ==========================================================
        // JIKA KE MANUSIA (Admin) -> Simpan ke Database
        // ==========================================================
        if ($receiverId !== 0) {
            $userMessage = Message::create([
                'sender_id' => $myId,
                'receiver_id' => $receiverId,
                'message' => $request->message
            ]);

            broadcast(new MessageSent($userMessage))->toOthers();
            return response()->json([
                'status' => 'success',
                'user_message' => $userMessage
            ]);
        }

        // ==========================================================
        // JIKA KE AI (receiver_id == 0) -> JANGAN SIMPAN KE DATABASE
        // Mencegah error Foreign Key Constraint Violation!
        // ==========================================================
        
        // Panggil Gemini (Synchronous cepat)
        $userText = $request->message;
        $aiResponseText = $this->generateGeminiResponse($userText);

        // Buat objek dummy (tidak masuk DB) agar React bisa merendernya
        $dummyAiMessage = [
            'id' => time() . rand(100, 999), // ID virtual sementara
            'sender_id' => 0, 
            'receiver_id' => $myId,
            'message' => $aiResponseText,
            'created_at' => now()->toIso8601String()
        ];

        // Langsung kembalikan respons ke Frontend
        return response()->json([
            'status' => 'success',
            'ai_message' => $dummyAiMessage 
        ]);
    }

    /**
     * Helper Function: Generate Balasan Gemini
     */
    private function generateGeminiResponse($userText)
    {
        try {
            // Ambil data produk sebagai bahan konteks AI
            $products = Product::where('status', 'active')
                ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description')
                ->take(15) 
                ->get();
            
            $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
            foreach ($products as $p) {
                $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
            }

            $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan gaya bahasa 'halo', 'kak', dll. Jawablah pertanyaan berdasarkan data produk berikut. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora, tolak dengan sangat halus.\n\n" . $dbContext;

            $apiKey = env('GEMINI_API_KEY');
            
            if (empty($apiKey)) {
                return "Mohon maaf kak, kunci API AI belum dikonfigurasi di server kami (.env).";
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

            $payload = [
                'system_instruction' => [
                    'parts' => [['text' => $systemInstruction]]
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userText]]]
                ]
            ];

            // Tembak API Gemini (Max nunggu 15 detik)
            $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya agak bingung. Bisa ulangi pertanyaannya?";
            }

            Log::error('Gemini API Error: ' . $response->body());
            return "Maaf kak, sistem koneksi AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

        } catch (\Exception $e) {
            Log::error('Gemini Exception: ' . $e->getMessage());
            return "Maaf kak, sistem AI sedang offline saat ini.";
        }
    }
}
