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
//         $userId = (int) $userId;

//         // Mencegah error database: Jika chat ke AI (0), return array kosong (history tidak disimpan di DB)
//         if ($userId === 0) {
//             return response()->json([]);
//         }

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

//         // ==========================================================
//         // JIKA KE MANUSIA (Admin) -> Simpan ke Database
//         // ==========================================================
//         if ($receiverId !== 0) {
//             $userMessage = Message::create([
//                 'sender_id' => $myId,
//                 'receiver_id' => $receiverId,
//                 'message' => $request->message
//             ]);

//             broadcast(new MessageSent($userMessage))->toOthers();
//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA KE AI (receiver_id == 0) -> JANGAN SIMPAN KE DATABASE
//         // Mencegah error Foreign Key Constraint Violation!
//         // ==========================================================

//         // Panggil Gemini (Synchronous cepat)
//         $userText = $request->message;
//         $aiResponseText = $this->generateGeminiResponse($userText);

//         // Buat objek dummy (tidak masuk DB) agar React bisa merendernya
//         $dummyAiMessage = [
//             'id' => time() . rand(100, 999), // ID virtual sementara
//             'sender_id' => 0,
//             'receiver_id' => $myId,
//             'message' => $aiResponseText,
//             'created_at' => now()->toIso8601String()
//         ];

//         // Langsung kembalikan respons ke Frontend
//         return response()->json([
//             'status' => 'success',
//             'ai_message' => $dummyAiMessage
//         ]);
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             // Ambil data produk sebagai bahan konteks AI
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
//                 ->take(15)
//                 ->get();

//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan gaya bahasa 'halo', 'kak', dll. Jawablah pertanyaan berdasarkan data produk berikut. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora, tolak dengan sangat halus.\n\n" . $dbContext;

//             $apiKey = env('GEMINI_API_KEY');

//             if (empty($apiKey)) {
//                 return "Mohon maaf kak, kunci API AI belum dikonfigurasi di server kami (.env).";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             $payload = [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemInstruction]]
//                 ],
//                 'contents' => [
//                     ['role' => 'user', 'parts' => [['text' => $userText]]]
//                 ]
//             ];

//             // Tembak API Gemini (Max nunggu 15 detik)
//             $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya agak bingung. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, sistem koneksi AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini.";
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
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {
//         // Ambil user dengan tipe 'admin' dan 'ai'
//         // orderByRaw membuat AI tampil paling atas di daftar kontak
//         $staff = User::whereIn('usertype', ['admin', 'ai'])
//             ->orderByRaw("FIELD(usertype, 'ai', 'admin')")
//             ->get();

//         return response()->json($staff);
//     }

//     // Mengambil histori pesan (Sekarang berlaku sama untuk Admin maupun AI)
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
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiver = User::findOrFail($request->receiver_id);

//         // 1. Simpan pesan pengguna ke database SECARA PERMANEN
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->usertype === 'ai') {

//             // Panggil Gemini (Membaca pesan user)
//             $aiResponseText = $this->generateGeminiResponse($request->message);

//             // 2. Simpan balasan AI ke database SECARA PERMANEN
//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id, // Pengirimnya adalah entitas AI
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             // Broadcast pesan AI via Pusher
//             broadcast(new MessageSent($aiMessage));

//             // Kembalikan balasan langsung agar UI merender dengan cepat
//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA PENERIMA ADALAH MANUSIA (Admin)
//         // ==========================================================
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', "is_bundle_active")
//                 ->take(15)
//                 ->get();

//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional (gunakan gaya bahasa 'halo', 'kak', dll). Jawablah pertanyaan pengguna berdasarkan data produk berikut ini. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora atau pertanyaan tidak jelas, tolak dengan sangat halus atau tawarkan untuk dihubungkan ke admin manusia.\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi oleh administrator di server.";
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
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban Anda. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini.";
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
//     // Mengambil daftar staf dan AI (Sistem Auto-Healing)
//     public function getStaffList() {

//         // 1. AUTO-CREATE AI JIKA BELUM ADA (Menghindari error Tinker/Database)
//         // Kita set usertype sebagai 'admin' agar lolos validasi ENUM database Anda
//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin', // Diterima database
//                 'phone' => '00000000000'
//             ]
//         );

//         // 2. Tarik Admin Manusia dan AI dari database
//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         // 3. "Sulap" data AI agar Frontend (React) mengenalinya sebagai Bot
//         $staff->transform(function ($user) {
//             if ($user->email === 'ai@gycora.com') {
//                 $user->usertype = 'ai'; // Trigger warna ungu & logika di frontend
//             }
//             return $user;
//         });

//         // 4. Urutkan agar Gycora AI selalu tampil paling atas di daftar Chat
//         $staff = $staff->sortByDesc(function ($user) {
//             return $user->usertype === 'ai' ? 1 : 0;
//         })->values();

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
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiver = User::findOrFail($request->receiver_id);

//         // 1. Simpan pesan pengguna ke database SECARA PERMANEN
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI (Dideteksi dari email)
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             // Panggil Gemini (Membaca pesan user)
//             $aiResponseText = $this->generateGeminiResponse($request->message);

//             // 2. Simpan balasan AI ke database SECARA PERMANEN
//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id, // Pengirimnya adalah entitas nyata AI di DB
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             // Broadcast pesan AI via Websocket (Pusher/Echo)
//             broadcast(new MessageSent($aiMessage));

//             // Kembalikan balasan langsung agar UI merender dengan cepat tanpa nunggu websocket
//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA PENERIMA ADALAH MANUSIA (Admin Manusia)
//         // ==========================================================
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             // Suplai data agar AI pintar menjawab pertanyaan produk
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
//                 ->take(15)
//                 ->get();

//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional (gunakan gaya bahasa 'halo', 'kak', dll). Jawablah pertanyaan pengguna berdasarkan data produk berikut ini. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora atau pertanyaan tidak jelas, tolak dengan sangat halus atau tawarkan untuk dihubungkan ke admin manusia.\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi oleh administrator di server.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

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
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban Anda. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini.";
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
//     // Mengambil daftar staf dan AI (Sistem Auto-Healing + Force Array)
//     public function getStaffList() {

//         // 1. AUTO-CREATE AI JIKA BELUM ADA
//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin', // Disimpan sebagai admin agar lolos database
//                 'phone' => '00000000000'
//             ]
//         );

//         // 2. Tarik Admin Manusia dan AI dari database
//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         // 3. "Sulap" data AI menggunakan MAP TO ARRAY agar tidak ditolak saat serialisasi JSON
//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai'; // Mutlak terganti menjadi 'ai'
//             }
//             return $data;
//         });

//         // 4. Urutkan agar Gycora AI selalu tampil paling atas
//         $staffArray = $staffArray->sortByDesc(function ($user) {
//             return $user['usertype'] === 'ai' ? 1 : 0;
//         })->values();

//         return response()->json($staffArray);
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
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiver = User::findOrFail($request->receiver_id);

//         // 1. Simpan pesan pengguna ke database
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             // Panggil Gemini (Membaca pesan user)
//             $aiResponseText = $this->generateGeminiResponse($request->message);

//             // 2. Simpan balasan AI ke database
//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             // Broadcast pesan AI via Websocket
//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA PENERIMA ADALAH MANUSIA
//         // ==========================================================
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
//                 ->take(15)
//                 ->get();

//             $dbContext = "Berikut adalah data produk Gycora saat ini:\n";
//             foreach ($products as $p) {
//                 $dbContext .= "- {$p->name} (Harga: {$p->price}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             $systemInstruction = "Kamu adalah Gycora AI, customer service yang ramah, sopan, dan informatif untuk website kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional (gunakan gaya bahasa 'halo', 'kak', dll). Jawablah pertanyaan pengguna berdasarkan data produk berikut ini. Jangan merekomendasikan harga di luar data. Jika pengguna bertanya hal di luar produk Gycora atau pertanyaan tidak jelas, tolak dengan sangat halus.\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi oleh administrator di server.";
//             }

//             // [PERBAIKAN MODEL]: Pastikan menggunakan 1.5-flash (tidak ada versi 3.5)
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

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
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini.";
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
//     // Mengambil daftar staf dan AI (Sistem Auto-Healing + Force Array)
//     public function getStaffList() {

//         // 1. AUTO-CREATE AI JIKA BELUM ADA
//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin', // Disimpan sebagai admin agar lolos database
//                 'phone' => '00000000000'
//             ]
//         );

//         // 2. Tarik Admin Manusia dan AI dari database
//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         // 3. "Sulap" data AI menggunakan MAP TO ARRAY agar tidak ditolak saat serialisasi JSON
//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai'; // Mutlak terganti menjadi 'ai'
//             }
//             return $data;
//         });

//         // 4. Urutkan agar Gycora AI selalu tampil paling atas
//         $staffArray = $staffArray->sortByDesc(function ($user) {
//             return $user['usertype'] === 'ai' ? 1 : 0;
//         })->values();

//         return response()->json($staffArray);
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
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiver = User::findOrFail($request->receiver_id);

//         // 1. Simpan pesan pengguna ke database
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             // Panggil Gemini (Membaca pesan user)
//             $aiResponseText = $this->generateGeminiResponse($request->message);

//             // 2. Simpan balasan AI ke database
//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             // Broadcast pesan AI via Websocket
//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA PENERIMA ADALAH MANUSIA
//         // ==========================================================
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
//                 ->take(15)
//                 ->get();

//             $dbContext = "DATA PRODUK GYCORA SAAT INI (REAL-TIME):\n";
//             foreach ($products as $p) {
//                 $harga = number_format($p->price, 0, ',', '.');
//                 $dbContext .= "- {$p->name} (Harga: Rp {$harga}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             // ====================================================================
//             // INJEKSI PENGETAHUAN HARDCODE (FAQ, Bantuan & Info Produk Gycora)
//             // ====================================================================
//             $hardcodedKnowledge = "
//             PENGETAHUAN PRODUK UNGGULAN:
//             1. Ethereal Glow Brush: Hairbrush anti-static dengan teknologi konduktif dan molekul karbon. Membantu rambut terasa lebih halus, rapi, dan mudah diatur dalam sekali sisir (mengurangi kusut dan mengembang). Cocok untuk semua jenis rambut (lurus, bergelombang). Aman untuk dipakai setiap hari. Bulu sisir fleksibel dan lembut (tidak sakit di kulit kepala) serta meminimalkan risiko rambut patah akibat gesekan. Cara membersihkan: gunakan air & sabun lembut, lalu keringkan.
//             2. Eco Serenity Scalp Care: Scalp massager yang membersihkan kulit kepala optimal (mengurangi tumpukan minyak/kotoran) dan memberi sensasi pijatan relaksasi. Bisa digunakan saat keramas atau saat rambut kering. Aman untuk kulit kepala sensitif berkat teeth (gigi sisir) yang lembut. Cara membersihkan: bilas air bersih & simpan di tempat kering.

//             BAHAN & KEAMANAN PRODUK:
//             - Aman untuk ibu hamil dan menyusui (formulasi tanpa Paraben dan SLS). Namun, tetap disarankan konsultasi ke dokter kandungan jika mencoba perawatan baru.
//             - Seluruh produk yang dijual adalah 100% Original.

//             PEMESANAN & PEMBAYARAN:
//             - Metode Pembayaran: Transfer Bank (BCA, Mandiri, BNI, BRI), Kartu Kredit/Debit, GoPay, OVO, ShopeePay, dan QRIS.
//             - Pembatalan/Perubahan: Hubungi Customer Service MAKSIMAL 1 jam setelah pembayaran. Jika lewat, pesanan langsung diproses sistem.

//             PENGIRIMAN (SHIPPING):
//             - Jangkauan: Seluruh Indonesia (belum melayani internasional).
//             - Estimasi Waktu: Jabodetabek (1-3 hari kerja), Luar Jawa (3-7 hari kerja) menyesuaikan ekspedisi.
//             - Pelacakan: Nomor resi dikirim via email dan bisa dilacak di menu 'Order' pada akun.

//             KEBIJAKAN RETUR & KOMPLAIN:
//             - Keluhan Barang Rusak/Tidak Sesuai: Harap hubungi tim support MAKSIMAL 1x24 jam sejak diterima. WAJIB menyertakan Video Unboxing dan foto produk.
//             - Batas waktu pengembalian barang umum: 14 hari sejak diterima (sesuai ketentuan di halaman Return Policy).
//             ";

//             // ====================================================================
//             // BANGUN SYSTEM PROMPT
//             // ====================================================================
//             $systemInstruction = "Kamu adalah Gycora AI, asisten virtual dan customer service yang ramah, sopan, dan informatif untuk brand kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional (panggil pelanggan dengan sapaan 'Kak').\n\nTUGAS UTAMA:\nJawab pertanyaan pengguna berdasarkan 'PENGETAHUAN PRODUK & KEBIJAKAN' serta 'DATA PRODUK GYCORA' yang disediakan. Ingatkan tentang video unboxing jika ada kendala pengiriman. Jangan merekomendasikan harga atau khasiat di luar data. Jika pengguna bertanya hal di luar produk Gycora atau tidak jelas, tolak dengan sangat halus.\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi oleh administrator di server.";
//             }

//             // [PERBAIKAN MODEL]: Menyesuaikan URL ke versi 1.5-flash agar valid dan tidak error
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

//             $payload = [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemInstruction]]
//                 ],
//                 'contents' => [
//                     ['role' => 'user', 'parts' => [['text' => $userText]]]
//                 ],
//                 'generationConfig' => [
//                     'temperature' => 0.4, // Suhu diatur rendah agar respons AI tetap akurat dan faktual
//                 ],
//             ];

//             $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi admin manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini.";
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
//     // Mengambil daftar staf dan AI (Sistem Auto-Healing + Force Array)
//     public function getStaffList() {

//         // 1. AUTO-CREATE AI JIKA BELUM ADA
//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin', // Disimpan sebagai admin agar lolos database
//                 'phone' => '00000000000'
//             ]
//         );

//         // 2. Tarik Admin Manusia dan AI dari database
//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         // 3. "Sulap" data AI menggunakan MAP TO ARRAY agar tidak ditolak saat serialisasi JSON
//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai'; // Mutlak terganti menjadi 'ai'
//             }
//             return $data;
//         });

//         // 4. Urutkan agar Gycora AI selalu tampil paling atas
//         $staffArray = $staffArray->sortByDesc(function ($user) {
//             return $user['usertype'] === 'ai' ? 1 : 0;
//         })->values();

//         return response()->json($staffArray);
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
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $receiver = User::findOrFail($request->receiver_id);

//         // 1. Simpan pesan pengguna ke database
//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             // Panggil Gemini (Membaca pesan user)
//             $aiResponseText = $this->generateGeminiResponse($request->message);

//             // 2. Simpan balasan AI ke database
//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             // Broadcast pesan AI via Websocket
//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }

//         // ==========================================================
//         // JIKA PENERIMA ADALAH MANUSIA
//         // ==========================================================
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini
//      */
//     private function generateGeminiResponse($userText)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
//                 ->take(15)
//                 ->get();

//             $dbContext = "DATA PRODUK GYCORA SAAT INI (REAL-TIME):\n";
//             foreach ($products as $p) {
//                 $harga = number_format($p->price, 0, ',', '.');
//                 $dbContext .= "- {$p->name} (Harga: Rp {$harga}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
//             }

//             // ====================================================================
//             // INJEKSI PENGETAHUAN HARDCODE (FAQ, TOS, Pengiriman, & Info Produk)
//             // ====================================================================
//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com
//             - Alamat: Surabaya, Jawa Timur 60226, Indonesia

//             PENGETAHUAN PRODUK UNGGULAN:
//             1. Ethereal Glow Brush: Hairbrush anti-static dengan teknologi konduktif dan molekul karbon. Membantu rambut terasa lebih halus, rapi, dan mudah diatur dalam sekali sisir. Cocok untuk semua jenis rambut. Aman dipakai setiap hari. Bulu sisir fleksibel meminimalkan risiko rambut patah. Cara membersihkan: gunakan air & sabun lembut, lalu keringkan.
//             2. Eco Serenity Scalp Care: Scalp massager yang membersihkan kulit kepala optimal dan memberi sensasi pijatan relaksasi. Bisa digunakan saat keramas atau rambut kering. Cara membersihkan: bilas air bersih & simpan di tempat kering.
//             - Formulasi tanpa Paraben dan SLS, aman untuk ibu hamil/menyusui (disarankan konsultasi dokter). 100% Original.

//             PEMESANAN, PEMBAYARAN, & PRIVASI:
//             - Metode Pembayaran: Transfer Bank, Kartu Kredit/Debit, GoPay, OVO, ShopeePay, dan QRIS.
//             - Pembatalan/Perubahan: Hubungi Customer Service MAKSIMAL 1 jam setelah pembayaran.
//             - Privasi & Keamanan: Tunduk pada UU PDP Indonesia. Data pribadi aman, kami tidak menyimpan detail kartu kredit. Pengguna berhak mengakses atau meminta penghapusan data via email.

//             PENGIRIMAN & LOGISTIK:
//             - Waktu Proses: 1 hari kerja (tidak termasuk akhir pekan/libur).
//             - Jangkauan: Domestik (Standar/Ekspres, tarif dihitung otomatis) & Internasional (Hubungi email untuk tarif; bea/pajak ditanggung pembeli).
//             - Pelacakan: Nomor resi dikirim via email, tunggu 1x24 jam untuk update sistem logistik.
//             - Kehilangan/Kerusakan Kurir: Gycora tidak bertanggung jawab atas paket yang hilang/rusak selama pengiriman oleh ekspedisi. Pembeli harus klaim langsung ke pihak kurir.

//             KEBIJAKAN RETUR & PENGEMBALIAN DANA (REFUND):
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima.
//             - Syarat Mutlak: Wajib mengirimkan Video Unboxing TANPA EDITAN ke gycora.essence@gmail.com.
//             - Biaya Kirim Retur: Seluruh biaya pengiriman barang retur ditanggung oleh pembeli.
//             - Penukaran (Exchange): Tidak bisa tukar langsung. Pelanggan harus meretur barang, tunggu refund, lalu melakukan pesanan/pembelian baru.
//             - Proses Refund: Jika disetujui, dana dikembalikan otomatis ke metode pembayaran asli dalam maksimal 30 hari kerja. Hubungi email kami jika lebih dari 15 hari kerja dana belum masuk.
//             ";

//             // ====================================================================
//             // BANGUN SYSTEM PROMPT
//             // ====================================================================
//             $systemInstruction = "Kamu adalah Gycora AI, asisten virtual dan customer service representatif untuk brand kecantikan Gycora. Gunakan bahasa Indonesia yang santai tapi profesional dan berempati (selalu panggil pengguna dengan sapaan 'Kak').\n\nTUGAS UTAMA:\nJawab pertanyaan pengguna secara akurat berdasarkan 'PENGETAHUAN PRODUK, TOS & KEBIJAKAN' serta 'DATA PRODUK GYCORA'. \n- Ingatkan tentang syarat ketat batas waktu 3 hari dan WAJIB video unboxing ke email jika ada keluhan pesanan.\n- Jika pengguna bertanya tarif internasional atau komplain lebih lanjut, arahkan ke email gycora.essence@gmail.com atau WA 082273736200.\n- JANGAN mengarang kebijakan atau harga di luar data yang diberikan. Tolak dengan halus jika ditanya hal di luar Gycora.\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi oleh administrator di server.";
//             }

//             // [PERBAIKAN MODEL]: Menyesuaikan URL ke versi 1.5-flash agar valid dan tidak error
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

//             $payload = [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemInstruction]]
//                 ],
//                 'contents' => [
//                     ['role' => 'user', 'parts' => [['text' => $userText]]]
//                 ],
//                 'generationConfig' => [
//                     'temperature' => 0.3, // Suhu diatur lebih rendah (0.3) agar AI sangat patuh pada dokumen kebijakan privasi & hukum
//                 ],
//             ];

//             $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban. Bisa ulangi pertanyaannya?";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami di gycora.essence@gmail.com ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200 untuk bantuan cepat.";
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Product;
use App\Models\Transaction; // [BARU] Tambahkan model Transaction
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    // Mengambil daftar staf dan AI (Sistem Auto-Healing + Force Array)
    public function getStaffList() {
        
        $aiUser = User::firstOrCreate(
            ['email' => 'ai@gycora.com'],
            [
                'first_name' => 'Gycora',
                'last_name' => 'AI Assistant',
                'password' => bcrypt('password_rahasia_ai_123'),
                'usertype' => 'admin', 
                'phone' => '00000000000'
            ]
        );

        $staff = User::where('usertype', 'admin')
            ->orWhere('email', 'ai@gycora.com')
            ->get();

        $staffArray = $staff->map(function ($user) {
            $data = $user->toArray();
            if ($data['email'] === 'ai@gycora.com') {
                $data['usertype'] = 'ai'; 
            }
            return $data;
        });

        $staffArray = $staffArray->sortByDesc(function ($user) {
            return $user['usertype'] === 'ai' ? 1 : 0;
        })->values();

        return response()->json($staffArray);
    }


    // Mengambil histori pesan
    public function getMessages($userId) {
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
            'receiver_id' => 'required|exists:users,id', 
            'message' => 'required|string'
        ]);

        $myId = auth()->id();
        $receiver = User::findOrFail($request->receiver_id);

        $userMessage = Message::create([
            'sender_id' => $myId,
            'receiver_id' => $receiver->id,
            'message' => $request->message
        ]);

        // ==========================================================
        // JIKA PENERIMA ADALAH AI
        // ==========================================================
        if ($receiver->email === 'ai@gycora.com') {
            
            // [PERBAIKAN]: Kita kirimkan $myId agar AI tahu milik siapa pesanan yang dicari
            $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

            $aiMessage = Message::create([
                'sender_id' => $receiver->id,
                'receiver_id' => $myId,
                'message' => $aiResponseText
            ]);

            broadcast(new MessageSent($aiMessage));

            return response()->json([
                'status' => 'success',
                'user_message' => $userMessage,
                'ai_message' => $aiMessage 
            ]);
        } 
        else {
            broadcast(new MessageSent($userMessage))->toOthers();
            
            return response()->json([
                'status' => 'success',
                'user_message' => $userMessage
            ]);
        }
    }

    /**
     * [BARU] Helper Local Function untuk mengecek database pesanan
     */
    private function cekStatusPesananLokal($userId, $orderId = null)
    {
        // Cari transaksi berdasarkan User ID (Pengguna yang sedang chat)
        $query = Transaction::where('user_id', $userId)->latest();

        // Jika AI menangkap pengguna mengetik Nomor Order ID spesifik
        if ($orderId) {
            $query->where('order_id', 'LIKE', '%' . $orderId . '%');
        }

        $transaction = $query->first();

        if (!$transaction) {
            return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
        }

        $result = [
            'order_id' => $transaction->order_id,
            'status_pembayaran' => $transaction->status,
            'metode_pengiriman' => $transaction->shipping_method,
            'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
            'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
            'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
        ];

        // Jika menggunakan Biteship dan statusnya sedang jalan, ambil status terbaru dari API
        if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
            try {
                $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
                    ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $biteshipData = $res->json();
                    $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
                    $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
                }
            } catch (\Exception $e) {
                // Abaikan error API, gunakan data dari database lokal saja
            }
        }

        return $result;
    }

    /**
     * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
     */
    private function generateGeminiResponse($userText, $userId) // Terima $userId di sini
    {
        try {
            $products = Product::where('status', 'active')
                ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
                ->take(15) 
                ->get();
            
            $dbContext = "DATA PRODUK GYCORA SAAT INI (REAL-TIME):\n";
            foreach ($products as $p) {
                $harga = number_format($p->price, 0, ',', '.');
                $dbContext .= "- {$p->name} (Harga: Rp {$harga}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
            }

            $hardcodedKnowledge = "
            INFORMASI PERUSAHAAN & KONTAK:
            - Nama: Gycora Essence
            - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com
            - Alamat: Surabaya, Jawa Timur 60226, Indonesia

            PENGETAHUAN PRODUK UNGGULAN:
            1. Ethereal Glow Brush: Hairbrush anti-static untuk rambut halus dan bebas kusut. Aman dipakai setiap hari.
            2. Eco Serenity Scalp Care: Scalp massager relaksasi kulit kepala.

            PEMESANAN & KEBIJAKAN RETUR:
            - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email. Biaya kirim retur ditanggung pembeli.
            - Proses Refund: Maksimal 30 hari kerja.
            ";

            $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jawab berdasarkan info yang ada.\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil alat/fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah dan menenangkan (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            
            if (empty($apiKey)) {
                return "Maaf kak, kunci API AI belum dikonfigurasi.";
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

            // ====================================================================
            // [BARU] 1. DEKLARASI ALAT/FUNGSI UNTUK AI (FUNCTION DECLARATION)
            // ====================================================================
            $tools = [
                [
                    'functionDeclarations' => [
                        [
                            'name' => 'lacak_pesanan_database',
                            'description' => 'Fungsi ini wajib dipanggil saat pengguna menanyakan status pesanan mereka, nomor resi, atau melacak paket. Fungsi ini akan mengecek database otomatis.',
                            'parameters' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'order_id' => [
                                        'type' => 'STRING',
                                        'description' => 'Masukkan ID Pesanan (contoh: SOL-123) jika pengguna menyebutkannya. Kosongkan jika tidak disebut.'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // ====================================================================
            // 2. KIRIM REQUEST PERTAMA KE GEMINI
            // ====================================================================
            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
                'tools' => $tools, // Masukkan fungsi ke otak AI
                'generationConfig' => ['temperature' => 0.3],
            ];

            $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

                // ====================================================================
                // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
                // ====================================================================
                if (isset($parts['functionCall'])) {
                    $functionName = $parts['functionCall']['name'];
                    $args = $parts['functionCall']['args'] ?? [];

                    if ($functionName === 'lacak_pesanan_database') {
                        // A. Eksekusi fungsi PHP lokal kita
                        $orderIdDicari = $args['order_id'] ?? null;
                        $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

                        // B. Kirim balik hasilnya (Data JSON) ke Gemini agar dirangkai jadi kalimat manis
                        $secondPayload = [
                            'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                            'contents' => [
                                ['role' => 'user', 'parts' => [['text' => $userText]]],
                                ['role' => 'model', 'parts' => [['functionCall' => $parts['functionCall']]]],
                                ['role' => 'function', 'parts' => [
                                    ['functionResponse' => [
                                        'name' => $functionName,
                                        'response' => $hasilDatabase // Data dari DB disuntikkan ke AI
                                    ]]
                                ]]
                            ],
                            'generationConfig' => ['temperature' => 0.4],
                        ];

                        $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);
                        
                        if ($secondResponse->successful()) {
                            $secondData = $secondResponse->json();
                            return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
                        }
                    }
                }

                // Jika AI merasa tidak perlu memanggil fungsi (hanya tanya biasa), balas teks biasa
                return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban.";
            }

            Log::error('Gemini API Error: ' . $response->body());
            return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami di gycora.essence@gmail.com ya.";

        } catch (\Exception $e) {
            Log::error('Gemini Exception: ' . $e->getMessage());
            return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200 untuk bantuan cepat.";
        }
    }
}