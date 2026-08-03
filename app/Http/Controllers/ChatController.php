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

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction; // [BARU] Tambahkan model Transaction
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI (Sistem Auto-Healing + Force Array)
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             // [PERBAIKAN]: Kita kirimkan $myId agar AI tahu milik siapa pesanan yang dicari
//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * [BARU] Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         // Cari transaksi berdasarkan User ID (Pengguna yang sedang chat)
//         $query = Transaction::where('user_id', $userId)->latest();

//         // Jika AI menangkap pengguna mengetik Nomor Order ID spesifik
//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         // Jika menggunakan Biteship dan statusnya sedang jalan, ambil status terbaru dari API
//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {
//                 // Abaikan error API, gunakan data dari database lokal saja
//             }
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId) // Terima $userId di sini
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com
//             - Alamat: Surabaya, Jawa Timur 60226, Indonesia

//             PENGETAHUAN PRODUK UNGGULAN:
//             1. Ethereal Glow Brush: Hairbrush anti-static untuk rambut halus dan bebas kusut. Aman dipakai setiap hari.
//             2. Eco Serenity Scalp Care: Scalp massager relaksasi kulit kepala.

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email. Biaya kirim retur ditanggung pembeli.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jawab berdasarkan info yang ada.\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil alat/fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah dan menenangkan (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // ====================================================================
//             // [BARU] 1. DEKLARASI ALAT/FUNGSI UNTUK AI (FUNCTION DECLARATION)
//             // ====================================================================
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi ini wajib dipanggil saat pengguna menanyakan status pesanan mereka, nomor resi, atau melacak paket. Fungsi ini akan mengecek database otomatis.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'Masukkan ID Pesanan (contoh: SOL-123) jika pengguna menyebutkannya. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             // ====================================================================
//             // 2. KIRIM REQUEST PERTAMA KE GEMINI
//             // ====================================================================
//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools, // Masukkan fungsi ke otak AI
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 // ====================================================================
//                 // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
//                 // ====================================================================
//                 if (isset($parts['functionCall'])) {
//                     $functionName = $parts['functionCall']['name'];
//                     $args = $parts['functionCall']['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {
//                         // A. Eksekusi fungsi PHP lokal kita
//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // B. Kirim balik hasilnya (Data JSON) ke Gemini agar dirangkai jadi kalimat manis
//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'contents' => [
//                                 ['role' => 'user', 'parts' => [['text' => $userText]]],
//                                 ['role' => 'model', 'parts' => [['functionCall' => $parts['functionCall']]]],
//                                 ['role' => 'function', 'parts' => [
//                                     ['functionResponse' => [
//                                         'name' => $functionName,
//                                         'response' => $hasilDatabase // Data dari DB disuntikkan ke AI
//                                     ]]
//                                 ]]
//                             ],
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         }
//                     }
//                 }

//                 // Jika AI merasa tidak perlu memanggil fungsi (hanya tanya biasa), balas teks biasa
//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami di gycora.essence@gmail.com ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200 untuk bantuan cepat.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // ==========================================================
//         // JIKA PENERIMA ADALAH AI
//         // ==========================================================
//         if ($receiver->email === 'ai@gycora.com') {

//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();

//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         // Cek Resi Biteship Real-time
//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {}
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId)
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com
//             - Alamat: Surabaya, Jawa Timur 60226, Indonesia

//             PENGETAHUAN PRODUK UNGGULAN:
//             1. Ethereal Glow Brush: Hairbrush anti-static untuk rambut halus dan bebas kusut. Aman dipakai setiap hari.
//             2. Eco Serenity Scalp Care: Scalp massager relaksasi kulit kepala.

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jawab berdasarkan info yang ada.\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah dan menenangkan (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // ====================================================================
//             // 1. DEKLARASI ALAT/FUNGSI UNTUK AI
//             // ====================================================================
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi ini wajib dipanggil saat pengguna menanyakan status pesanan mereka, nomor resi, atau melacak paket. Fungsi ini akan mengecek database otomatis.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'Masukkan ID Pesanan (contoh: SOL-123) jika pengguna menyebutkannya. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             // ====================================================================
//             // 2. KIRIM REQUEST PERTAMA KE GEMINI
//             // ====================================================================
//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 // ====================================================================
//                 // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
//                 // ====================================================================
//                 if (isset($parts['functionCall'])) {
//                     $functionName = $parts['functionCall']['name'];
//                     $args = $parts['functionCall']['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {
//                         // A. Eksekusi fungsi PHP lokal kita
//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // B. Kirim balik hasilnya ke Gemini
//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools, // <-- [PERBAIKAN MUTLAK] Wajib dikirim ulang di sini!
//                             'contents' => [
//                                 ['role' => 'user', 'parts' => [['text' => $userText]]],
//                                 ['role' => 'model', 'parts' => [['functionCall' => $parts['functionCall']]]],
//                                 ['role' => 'function', 'parts' => [
//                                     ['functionResponse' => [
//                                         'name' => $functionName,
//                                         // Dibungkus "data_pesanan" agar sah sebagai JSON Object
//                                         'response' => ['data_pesanan' => $hasilDatabase]
//                                     ]]
//                                 ]]
//                             ],
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         } else {
//                             // [PERBAIKAN] Log Error agar ketahuan jika API masih menolak
//                             Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
//                             return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
//                         }
//                     }
//                 }

//                 // Jika AI tidak perlu memanggil fungsi, balas biasa
//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami di gycora.essence@gmail.com ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200 untuk bantuan cepat.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         if ($receiver->email === 'ai@gycora.com') {

//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();

//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {}
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId)
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com
//             - Alamat: Surabaya, Jawa Timur 60226, Indonesia

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jawab berdasarkan info yang ada.\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah dan menenangkan (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi wajib dipanggil saat pengguna melacak pesanan.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'ID Pesanan. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {

//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // 👇 [PERBAIKAN MUTLAK]: Memaksa Array Kosong di PHP menjadi Object JSON 👇
//                         if (empty($functionCall['args'])) {
//                             $functionCall['args'] = new \stdClass();
//                         }

//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools,
//                             'contents' => [
//                                 ['role' => 'user', 'parts' => [['text' => $userText]]],
//                                 ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]],
//                                 ['role' => 'function', 'parts' => [
//                                     ['functionResponse' => [
//                                         'name' => $functionName,
//                                         'response' => ['result' => $hasilDatabase] // Dibungkus 'result'
//                                     ]]
//                                 ]]
//                             ],
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         } else {
//                             Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
//                             return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
//                         }
//                     }
//                 }

//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         if ($receiver->email === 'ai@gycora.com') {

//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();

//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {}
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId)
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // ====================================================================
//             // 1. DEKLARASI ALAT/FUNGSI UNTUK AI
//             // ====================================================================
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi wajib dipanggil saat pengguna melacak pesanan.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'ID Pesanan. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 // ====================================================================
//                 // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
//                 // ====================================================================
//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {

//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // 👇 [PERBAIKAN MUTLAK]: Membangun ulang struktur Function Call
//                         // untuk mencegah PHP mengubah Object {} menjadi Array []
//                         $safeFunctionCall = [
//                             'name' => $functionName,
//                             'args' => empty($args) ? new \stdClass() : (object) $args
//                         ];

//                         // 👇 [PERBAIKAN MUTLAK]: Struktur "functionResponse" yang berlapis
//                         // sesuai standar baku Google Gemini REST API
//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools,
//                             'contents' => [
//                                 ['role' => 'user', 'parts' => [['text' => $userText]]],
//                                 ['role' => 'model', 'parts' => [['functionCall' => $safeFunctionCall]]],
//                                 ['role' => 'function', 'parts' => [
//                                     ['functionResponse' => [
//                                         'name' => $functionName,
//                                         'response' => [
//                                             'name' => $functionName,
//                                             'content' => $hasilDatabase // Data disuntikkan ke dalam objek 'content'
//                                         ]
//                                     ]]
//                                 ]]
//                             ],
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         } else {
//                             Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
//                             return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
//                         }
//                     }
//                 }

//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         if ($receiver->email === 'ai@gycora.com') {

//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();

//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {}
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId)
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // ====================================================================
//             // 1. DEKLARASI ALAT/FUNGSI UNTUK AI
//             // ====================================================================
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi wajib dipanggil saat pengguna melacak pesanan.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'ID Pesanan. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 // ====================================================================
//                 // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
//                 // ====================================================================
//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {

//                         // A. Dapatkan data dari database lokal
//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // B. [PERBAIKAN MUTLAK] "PELURU PERAK" KONVERSI JSON
//                         // Ini memastikan PHP tidak salah menerjemahkan array menjadi bentuk yang ditolak Gemini
//                         $safeArgs = empty($args) ? new \stdClass() : json_decode(json_encode($args), false);
//                         $safeResponse = json_decode(json_encode($hasilDatabase), false);

//                         // C. Susun ulang pesan secara manual sesuai standar kaku Google API
//                         $msg1 = ['role' => 'user', 'parts' => [['text' => $userText]]];

//                         $msg2 = ['role' => 'model', 'parts' => [
//                             ['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]
//                         ]];

//                         $msg3 = ['role' => 'function', 'parts' => [
//                             ['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]
//                         ]];

//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools,
//                             'contents' => [$msg1, $msg2, $msg3], // Digabung menjadi riwayat percakapan yang runtut
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         } else {
//                             // Mencatat pesan error asli dari server Google jika masih gagal
//                             Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
//                             return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
//                         }
//                     }
//                 }

//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;

// class ChatController extends Controller
// {
//     // Mengambil daftar staf dan AI
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'AI Assistant',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         $staff = User::where('usertype', 'admin')
//             ->orWhere('email', 'ai@gycora.com')
//             ->get();

//         $staffArray = $staff->map(function ($user) {
//             $data = $user->toArray();
//             if ($data['email'] === 'ai@gycora.com') {
//                 $data['usertype'] = 'ai';
//             }
//             return $data;
//         });

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

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         if ($receiver->email === 'ai@gycora.com') {

//             $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//             $aiMessage = Message::create([
//                 'sender_id' => $receiver->id,
//                 'receiver_id' => $myId,
//                 'message' => $aiResponseText
//             ]);

//             broadcast(new MessageSent($aiMessage));

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage,
//                 'ai_message' => $aiMessage
//             ]);
//         }
//         else {
//             broadcast(new MessageSent($userMessage))->toOthers();

//             return response()->json([
//                 'status' => 'success',
//                 'user_message' => $userMessage
//             ]);
//         }
//     }

//     /**
//      * Helper Local Function untuk mengecek database pesanan
//      */
//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();

//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }

//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//             'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
//         ];

//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                     $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
//                 }
//             } catch (\Exception $e) {}
//         }

//         return $result;
//     }

//     /**
//      * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
//      */
//     private function generateGeminiResponse($userText, $userId)
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

//             $hardcodedKnowledge = "
//             INFORMASI PERUSAHAAN & KONTAK:
//             - Nama: Gycora Essence
//             - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com

//             PEMESANAN & KEBIJAKAN RETUR:
//             - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
//             - Proses Refund: Maksimal 30 hari kerja.
//             ";

//             $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

//             if (empty($apiKey)) {
//                 return "Maaf kak, kunci API AI belum dikonfigurasi.";
//             }

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // ====================================================================
//             // 1. DEKLARASI ALAT/FUNGSI UNTUK AI
//             // ====================================================================
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Fungsi wajib dipanggil saat pengguna melacak pesanan.',
//                             'parameters' => [
//                                 'type' => 'OBJECT',
//                                 'properties' => [
//                                     'order_id' => [
//                                         'type' => 'STRING',
//                                         'description' => 'ID Pesanan. Kosongkan jika tidak disebut.'
//                                     ]
//                                 ]
//                             ]
//                         ]
//                     ]
//                 ]
//             ];

//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.3],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 // ====================================================================
//                 // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
//                 // ====================================================================
//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     if ($functionName === 'lacak_pesanan_database') {

//                         // A. Dapatkan data dari database lokal
//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         // B. [PERBAIKAN FINAL]: Memaksa Array Kosong menjadi Objek Kosong
//                         // menggunakan JSON_FORCE_OBJECT
//                         $encodedArgs = json_encode($args, JSON_FORCE_OBJECT);
//                         $safeArgs = json_decode($encodedArgs, false);

//                         // C. [PERBAIKAN FINAL]: Membungkus response di dalam "result"
//                         // sesuai struktur mutlak REST API Gemini
//                         $wrappedResponse = [
//                             'result' => $hasilDatabase
//                         ];

//                         $encodedResponse = json_encode($wrappedResponse, JSON_FORCE_OBJECT);
//                         $safeResponse = json_decode($encodedResponse, false);

//                         // D. Susun ulang pesan secara manual
//                         $msg1 = ['role' => 'user', 'parts' => [['text' => $userText]]];

//                         $msg2 = ['role' => 'model', 'parts' => [
//                             ['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]
//                         ]];

//                         $msg3 = ['role' => 'function', 'parts' => [
//                             ['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]
//                         ]];

//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools,
//                             'contents' => [$msg1, $msg2, $msg3],
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             $secondData = $secondResponse->json();
//                             return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         } else {
//                             // [PENTING] Jika masih error, pesan ini akan muncul di file storage/logs/laravel.log Anda
//                             Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
//                             return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
//                         }
//                     }
//                 }

//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200.";
//         }
//     }
// }

// MOdifikasi ke 1 akun admin dengan implementasi AI

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Cache; // [BARU] Import Cache untuk mode Handoff

// class ChatController extends Controller
// {
//     // 1. [PERBAIKAN] Mengambil daftar staf (Hanya memunculkan 1 akun resmi)
//     public function getStaffList() {
//         $supportUser = User::firstOrCreate(
//             ['email' => 'support@gycora.com'],
//             [
//                 'first_name' => 'Gycora',
//                 'last_name' => 'Care',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'cs', // Role Customer Service
//                 'phone' => '00000000000'
//             ]
//         );

//         $supportUser->is_official = true;

//         // Hanya mengembalikan 1 akun Unified Inbox ke halaman customer
//         return response()->json([$supportUser]);
//     }

//     // 2. [PERBAIKAN] Mengambil histori pesan (Admin Manusia menyamar jadi Gycora Care)
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $me = User::find($myId);

//         // Jika yang sedang login adalah ADMIN MANUSIA, paksa ID-nya menjadi Gycora Care
//         if (in_array($me->usertype, ['admin', 'superadmin', 'cs'])) {
//             $supportUser = User::where('email', 'support@gycora.com')->first();
//             if($supportUser) $myId = $supportUser->id;
//         }

//         $messages = Message::where(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // 3. [PERBAIKAN] Menyimpan pesan & Logika Handoff AI
//     public function sendMessage(Request $request) {
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $myId = auth()->id();
//         $me = User::find($myId);
//         $senderId = $myId;

//         // Jika Admin manusia yang membalas, kirim atas nama Gycora Care
//         if (in_array($me->usertype, ['admin', 'superadmin', 'cs'])) {
//             $supportUser = User::where('email', 'support@gycora.com')->first();
//             if($supportUser) $senderId = $supportUser->id;
//         }

//         $receiver = User::findOrFail($request->receiver_id);

//         $userMessage = Message::create([
//             'sender_id' => $senderId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // JIKA PENERIMA ADALAH AKUN RESMI GYCORA CARE & PENGIRIMNYA BUKAN ADMIN
//         if ($receiver->email === 'support@gycora.com' && !in_array($me->usertype, ['admin', 'superadmin', 'cs'])) {

//             // Cek apakah chat ini sedang dalam mode 'ai' atau 'human'
//             $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

//             if ($chatMode === 'ai') {
//                 $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//                 if ($aiResponseText) {
//                     $aiMessage = Message::create([
//                         'sender_id' => $receiver->id,
//                         'receiver_id' => $myId,
//                         'message' => $aiResponseText
//                     ]);

//                     broadcast(new MessageSent($aiMessage))->toOthers();

//                     return response()->json([
//                         'status' => 'success',
//                         'user_message' => $userMessage,
//                         'ai_message' => $aiMessage
//                     ]);
//                 }
//             }
//             // Jika mode 'human', AI diam saja (Handoff), tunggu Admin manusia membalas di Panel Admin.
//         }

//         broadcast(new MessageSent($userMessage))->toOthers();
//         return response()->json(['status' => 'success', 'user_message' => $userMessage]);
//     }

//     private function cekStatusPesananLokal($userId, $orderId = null)
//     {
//         $query = Transaction::where('user_id', $userId)->latest();
//         if ($orderId) {
//             $query->where('order_id', 'LIKE', '%' . $orderId . '%');
//         }
//         $transaction = $query->first();

//         if (!$transaction) {
//             return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
//         }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'metode_pengiriman' => $transaction->shipping_method,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//         ];

//         if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
//             try {
//                 $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
//                     ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);
//                 if ($res->successful()) {
//                     $biteshipData = $res->json();
//                     $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
//                 }
//             } catch (\Exception $e) {}
//         }
//         return $result;
//     }

//     // 4. [PERBAIKAN] Otak Gemini Hybrid (Ada tools Handoff)
//     private function generateGeminiResponse($userText, $userId)
//     {
//         try {
//             $products = Product::where('status', 'active')
//                 ->select('name', 'price', 'stock', 'description')
//                 ->take(15)->get();

//             $dbContext = "DATA PRODUK GYCORA (REAL-TIME):\n";
//             foreach ($products as $p) {
//                 $harga = number_format($p->price, 0, ',', '.');
//                 $dbContext .= "- {$p->name} (Rp {$harga}, Stok: {$p->stock})\n";
//             }

//             $hardcodedKnowledge = "
//             INFO KONTAK: Gycora Essence. WA: 082273736200 | Email: gycora.essence@gmail.com
//             KEBIJAKAN RETUR: Maksimal 3 HARI. Wajib Video Unboxing tanpa edit.
//             ";

//             $systemInstruction = "Kamu adalah Gycora Care, asisten virtual resmi Gycora. Sapa pengguna 'Kak'.
//             TUGAS MUTLAK:
//             1. JIKA pengguna secara eksplisit/jelas meminta bicara dengan ADMIN MANUSIA, atau jika keluhannya sangat marah/kompleks, WAJIB panggil fungsi 'transfer_to_human'.
//             2. Jika melacak resi, panggil fungsi 'lacak_pesanan_database'.\n" . $hardcodedKnowledge . "\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
//             if (empty($apiKey)) return "Maaf kak, kunci API AI belum dikonfigurasi.";

//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             // DAFTARKAN TOOLS
//             $tools = [
//                 [
//                     'functionDeclarations' => [
//                         [
//                             'name' => 'lacak_pesanan_database',
//                             'description' => 'Panggil saat melacak pesanan.',
//                             'parameters' => ['type' => 'OBJECT', 'properties' => ['order_id' => ['type' => 'STRING']]]
//                         ],
//                         [
//                             'name' => 'transfer_to_human',
//                             'description' => 'Panggil fungsi ini jika pengguna minta ngobrol langsung sama admin/manusia.'
//                         ]
//                     ]
//                 ]
//             ];

//             // Riwayat percakapan untuk konteks (ambil 5 pesan terakhir)
//             $history = Message::where(function($q) use ($userId) {
//                 $supportUser = User::where('email', 'support@gycora.com')->first();
//                 $q->where('sender_id', $userId)->where('receiver_id', $supportUser->id);
//             })->orWhere(function($q) use ($userId) {
//                 $supportUser = User::where('email', 'support@gycora.com')->first();
//                 $q->where('sender_id', $supportUser->id)->where('receiver_id', $userId);
//             })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

//             $geminiContents = [];
//             $lastRole = '';

//             foreach ($history as $chat) {
//                 if (empty(trim($chat->message))) continue;
//                 $role = $chat->sender_id === $userId ? 'user' : 'model';

//                 if ($role === $lastRole) {
//                     $lastIndex = count($geminiContents) - 1;
//                     $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//                 } else {
//                     $geminiContents[] = [
//                         'role' => $role,
//                         'parts' => [['text' => $chat->message]],
//                     ];
//                     $lastRole = $role;
//                 }
//             }

//             // Tambahkan pesan terbaru user
//             $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

//             $payload = [
//                 'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                 'contents' => $geminiContents,
//                 'tools' => $tools,
//                 'generationConfig' => ['temperature' => 0.4],
//             ];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     // EKSEKUSI: HANDOFF KE MANUSIA
//                     if ($functionName === 'transfer_to_human') {
//                         // Ubah mode ke Human selama 24 Jam
//                         Cache::put('chat_mode_' . $userId, 'human', now()->addHours(24));
//                         return "Baik Kak, mohon ditunggu sebentar ya. Saya sudah memanggil tim Admin Manusia kami. Mereka akan segera membalas pesan Kakak langsung di ruang obrolan ini 🙏";
//                     }

//                     // EKSEKUSI: LACAK PESANAN
//                     if ($functionName === 'lacak_pesanan_database') {
//                         $orderIdDicari = $args['order_id'] ?? null;
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

//                         $safeArgs = empty($args) ? new \stdClass() : json_decode(json_encode($args), false);
//                         $safeResponse = json_decode(json_encode(['result' => $hasilDatabase]), false);

//                         $geminiContents[] = ['role' => 'model', 'parts' => [['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]]];
//                         $geminiContents[] = ['role' => 'function', 'parts' => [['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]]];

//                         $secondPayload = [
//                             'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
//                             'tools' => $tools,
//                             'contents' => $geminiContents,
//                             'generationConfig' => ['temperature' => 0.4],
//                         ];

//                         $secondResponse = Http::timeout(20)->post($url, $secondPayload);
//                         if ($secondResponse->successful()) {
//                             return $secondResponse->json('candidates.0.content.parts.0.text') ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
//                         }
//                     }
//                 }

//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }

//             Log::error('Gemini API Error Asli: ' . $response->body());

//             return "Maaf kak, sistem sedang sibuk. Mohon tunggu admin kami membalas ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, asisten AI sedang offline. Pesan kakak akan dibalas oleh tim kami segera.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Cache; // [BARU] Import Cache untuk mode Handoff

// class ChatController extends Controller
// {
//     // 1. [PERBAIKAN] Mengambil daftar staf (Unified Inbox Gycora Care)
//     public function getStaffList() {

//         $aiUser = User::firstOrCreate(
//             ['email' => 'ai@gycora.com'],
//             ['first_name' => 'Gycora', 'last_name' => 'AI Assistant', 'password' => bcrypt('password_rahasia_ai_123'), 'usertype' => 'admin', 'phone' => '00000000000']
//         );

//         // Ambil salah satu admin utama sebagai "wajah" dari Gycora Care
//         $mainAdmin = User::where('email', '!=', 'ai@gycora.com')->where('usertype', 'admin')->first();

//         if($mainAdmin) {
//             // Override virtual (tidak mengubah database) agar di frontend tampil elegan
//             $mainAdmin->first_name = "Gycora";
//             $mainAdmin->last_name = "Care";
//             $mainAdmin->usertype = "Official Account";
//             return response()->json([$mainAdmin]);
//         }

//         return response()->json([$aiUser]);
//     }

//     // 2. [PERBAIKAN] Mengambil histori pesan (Menarik pesan gabungan Admin + AI)
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $me = User::find($myId);

//         // Kumpulkan semua ID Admin dan AI dalam satu wadah
//         $adminIds = User::where('usertype', 'admin')->pluck('id')->toArray();

//         if ($me->usertype === 'user') {
//             // Jika pelanggan: Ambil semua pesan antara dia dan SELURUH admin/AI
//             $messages = Message::where(function($q) use ($myId, $adminIds) {
//                 $q->where('sender_id', $myId)->whereIn('receiver_id', $adminIds);
//             })->orWhere(function($q) use ($myId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $myId);
//             })->with('sender')->orderBy('created_at', 'asc')->get();
//         } else {
//             // Jika Admin: Ambil semua pesan antara pelanggan tsb dan SELURUH admin/AI
//             $messages = Message::where(function($q) use ($userId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
//             })->orWhere(function($q) use ($userId, $adminIds) {
//                 $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
//             })->with('sender')->orderBy('created_at', 'asc')->get();
//         }

//         return response()->json($messages);
//     }

//     // 3. [PERBAIKAN] Menyimpan pesan & Logika Handoff AI Cerdas
//     public function sendMessage(Request $request) {
//         $request->validate(['receiver_id' => 'required|exists:users,id', 'message' => 'required|string']);

//         $myId = auth()->id();
//         $me = User::find($myId);
//         $receiver = User::findOrFail($request->receiver_id);

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         // Broadcast pesan asli ke Websocket (Sertakan relasi sender agar UI mendeteksi warnanya)
//         broadcast(new MessageSent($userMessage->load('sender')))->toOthers();

//         // LOGIKA HYBRID: Memicu AI HANYA jika Pelanggan mengirim pesan ke Admin
//         if ($me->usertype === 'user' && $receiver->usertype === 'admin') {

//             // Cek mode chat (Default: ai)
//             $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

//             if ($chatMode === 'ai') {
//                 $aiUser = User::where('email', 'ai@gycora.com')->first();
//                 $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//                 if ($aiResponseText) {
//                     $aiMessage = Message::create([
//                         'sender_id' => $aiUser->id,
//                         'receiver_id' => $myId,
//                         'message' => $aiResponseText
//                     ]);

//                     broadcast(new MessageSent($aiMessage->load('sender')))->toOthers();

//                     return response()->json([
//                         'status' => 'success',
//                         'user_message' => $userMessage->load('sender'),
//                         'ai_message' => $aiMessage->load('sender')
//                     ]);
//                 }
//             }
//             // Jika mode = 'human', AI diam (Handoff berhasil)
//         }

//         return response()->json(['status' => 'success', 'user_message' => $userMessage->load('sender')]);
//     }

//     private function cekStatusPesananLokal($userId, $orderId = null) {
//         $query = Transaction::where('user_id', $userId)->latest();
//         if ($orderId) { $query->where('order_id', 'LIKE', '%' . $orderId . '%'); }
//         $transaction = $query->first();

//         if (!$transaction) { return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.']; }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//         ];
//         return $result;
//     }

//     // 4. Otak Gemini Hybrid (Ada fungsi Handoff ke Manusia)
//     private function generateGeminiResponse($userText, $userId) {
//         try {
//             $products = Product::where('status', 'active')->take(15)->get();
//             $dbContext = "DATA PRODUK GYCORA (REAL-TIME):\n";
//             foreach ($products as $p) {
//                 $harga = number_format($p->price, 0, ',', '.');
//                 $dbContext .= "- {$p->name} (Rp {$harga}, Stok: {$p->stock})\n";
//             }

//             $hardcodedKnowledge = "
//             INFO KONTAK: Gycora Essence. WA: 082273736200 | Email: gycora.essence@gmail.com
//             KEBIJAKAN RETUR: Maksimal 3 HARI. Wajib Video Unboxing tanpa edit.
//             ";

//             $systemInstruction = "Kamu adalah Gycora Care, asisten virtual resmi Gycora. Sapa pengguna 'Kak'.
//             TUGAS MUTLAK:
//             1. JIKA pengguna secara eksplisit/jelas meminta bicara dengan ADMIN MANUSIA, atau keluhannya sangat marah/kompleks, WAJIB panggil fungsi 'transfer_to_human'.
//             2. Jika pengguna melacak pesanan/resi, panggil fungsi 'lacak_pesanan_database'.\n" . $hardcodedKnowledge . "\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
//             // [PERBAIKAN] Menggunakan model -latest untuk mencegah Error 404
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-latest:generateContent?key=' . $apiKey;

//             $tools = [
//                 ['functionDeclarations' => [
//                     ['name' => 'lacak_pesanan_database', 'description' => 'Panggil saat melacak pesanan.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['order_id' => ['type' => 'STRING']]]],
//                     ['name' => 'transfer_to_human', 'description' => 'Panggil fungsi ini jika pengguna minta bicara dengan admin asli.']
//                 ]]
//             ];

//             // Riwayat percakapan untuk konteks AI
//             $adminIds = User::where('usertype', 'admin')->pluck('id')->toArray();
//             $history = Message::where(function($q) use ($userId, $adminIds) {
//                 $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
//             })->orWhere(function($q) use ($userId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
//             })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

//             $geminiContents = [];
//             $lastRole = '';
//             foreach ($history as $chat) {
//                 if (empty(trim($chat->message))) continue;
//                 $role = $chat->sender_id === $userId ? 'user' : 'model';
//                 if ($role === $lastRole) {
//                     $lastIndex = count($geminiContents) - 1;
//                     $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//                 } else {
//                     $geminiContents[] = ['role' => $role, 'parts' => [['text' => $chat->message]]];
//                     $lastRole = $role;
//                 }
//             }

//             $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

//             $payload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'contents' => $geminiContents, 'tools' => $tools, 'generationConfig' => ['temperature' => 0.4]];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     // EKSEKUSI 1: HANDOFF KE MANUSIA
//                     if ($functionName === 'transfer_to_human') {
//                         Cache::put('chat_mode_' . $userId, 'human', now()->addHours(24));
//                         return "Baik Kak, mohon ditunggu sebentar ya. Saya sudah memanggil tim Admin Manusia kami. Mereka akan segera membalas pesan Kakak langsung di ruang obrolan ini 🙏";
//                     }

//                     // EKSEKUSI 2: LACAK PESANAN (Dilengkapi Pelindung JSON)
//                     if ($functionName === 'lacak_pesanan_database') {
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $args['order_id'] ?? null);

//                         $safeArgs = json_decode(json_encode($args, JSON_FORCE_OBJECT), false);
//                         $safeResponse = json_decode(json_encode(['result' => $hasilDatabase], JSON_FORCE_OBJECT), false);

//                         $geminiContents[] = ['role' => 'model', 'parts' => [['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]]];
//                         $geminiContents[] = ['role' => 'function', 'parts' => [['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]]];

//                         $secondPayload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'tools' => $tools, 'contents' => $geminiContents, 'generationConfig' => ['temperature' => 0.4]];
//                         $secondResponse = Http::timeout(20)->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             return $secondResponse->json('candidates.0.content.parts.0.text') ?? "Maaf kak, saya gagal menerjemahkan pesanan.";
//                         }
//                     }
//                 }
//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }
//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, sistem AI sedang sibuk. Mohon tunggu admin kami membalas ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, asisten AI sedang offline. Pesan kakak akan dibalas oleh tim kami segera.";
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\Transaction;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Cache;

// class ChatController extends Controller
// {
//     // 1. Mengambil daftar staf (Unified Inbox Gycora Care)
//     // public function getStaffList() {
//     //     $aiUser = User::firstOrCreate(
//     //         ['email' => 'ai@gycora.com'],
//     //         ['first_name' => 'Gycora', 'last_name' => 'AI Assistant', 'password' => bcrypt('password_rahasia_ai_123'), 'usertype' => 'admin', 'phone' => '00000000000']
//     //     );

//     //     $mainAdmin = User::where('email', '!=', 'ai@gycora.com')->whereIn('usertype', ['admin', 'superadmin', 'cs'])->first();

//     //     if($mainAdmin) {
//     //         $mainAdmin->first_name = "Gycora";
//     //         $mainAdmin->last_name = "Care";
//     //         $mainAdmin->usertype = "Official Account";
//     //         return response()->json([$mainAdmin]);
//     //     }

//     //     return response()->json([$aiUser]);
//     // }

//     public function getStaffList() {
//         $myId = auth()->id();
//         $aiUser = User::firstOrCreate(['email' => 'ai@gycora.com'], ['first_name' => 'Gycora', 'last_name' => 'AI Assistant', 'password' => bcrypt('password_rahasia_ai_123'), 'usertype' => 'admin', 'phone' => '00000000000']);
//         $mainAdmin = User::where('email', '!=', 'ai@gycora.com')->whereIn('usertype', ['admin', 'superadmin', 'cs'])->first();

//         if($mainAdmin) {
//             $mainAdmin->first_name = "Gycora";
//             $mainAdmin->last_name = "Care";
//             $mainAdmin->usertype = "Official Account";

//             // Hitung pesan belum terbaca
//             $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
//             if (!in_array($aiUser->id, $adminIds)) $adminIds[] = $aiUser->id;

//             $mainAdmin->unread_count = Message::whereIn('sender_id', $adminIds)->where('receiver_id', $myId)->where('is_read', false)->count();
//             return response()->json([$mainAdmin]);
//         }
//         return response()->json([$aiUser]);
//     }

//     // 2. [PERBAIKAN] Mengambil histori pesan (Deteksi Pelanggan Dinamis)
//     public function getMessages($userId) {
//         $myId = auth()->id();
//         $me = User::find($myId);

//         // Kumpulkan semua ID Admin, CS, dan Superadmin
//         $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();

//         // Pastikan AI juga masuk dalam deteksi
//         $aiUser = User::where('email', 'ai@gycora.com')->first();
//         if ($aiUser && !in_array($aiUser->id, $adminIds)) {
//             $adminIds[] = $aiUser->id;
//         }

//         // 👇 PERBAIKAN: Jika usertype BUKAN admin/superadmin/cs, maka dia adalah Pelanggan (User/Reseller/Member)
//         $isCustomer = !in_array($me->usertype, ['admin', 'superadmin', 'cs']);

//         if ($isCustomer) {
//             // Logika Pelanggan: Ambil semua pesan antara Pelanggan (saya) dan SELURUH admin/AI
//             $messages = Message::where(function($q) use ($myId, $adminIds) {
//                 $q->where('sender_id', $myId)->whereIn('receiver_id', $adminIds);
//             })->orWhere(function($q) use ($myId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $myId);
//             })->with('sender')->orderBy('created_at', 'asc')->get();
//         } else {
//             // Logika Admin: Ambil semua pesan antara Pelanggan tertentu (userId) dan SELURUH admin/AI
//             $messages = Message::where(function($q) use ($userId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
//             })->orWhere(function($q) use ($userId, $adminIds) {
//                 $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
//             })->with('sender')->orderBy('created_at', 'asc')->get();
//         }

//         return response()->json($messages);
//     }

//     // 3. [PERBAIKAN] Menyimpan pesan & Pemicu AI
//     public function sendMessage(Request $request) {
//         $request->validate(['receiver_id' => 'required|exists:users,id', 'message' => 'required|string']);

//         $myId = auth()->id();
//         $me = User::find($myId);
//         $receiver = User::findOrFail($request->receiver_id);

//         $userMessage = Message::create([
//             'sender_id' => $myId,
//             'receiver_id' => $receiver->id,
//             'message' => $request->message
//         ]);

//         broadcast(new MessageSent($userMessage->load('sender')))->toOthers();

//         // 👇 PERBAIKAN: Logika dinamis untuk mendeteksi Pelanggan dan Admin
//         $isCustomer = !in_array($me->usertype, ['admin', 'superadmin', 'cs']);
//         $isReceiverAdmin = in_array($receiver->usertype, ['admin', 'superadmin', 'cs']) || $receiver->email === 'ai@gycora.com';

//         // Jika Pelanggan mengirim pesan ke Admin/AI
//         if ($isCustomer && $isReceiverAdmin) {

//             $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

//             if ($chatMode === 'ai') {
//                 $aiUser = User::where('email', 'ai@gycora.com')->first();
//                 $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

//                 if ($aiResponseText) {
//                     $aiMessage = Message::create([
//                         'sender_id' => $aiUser->id,
//                         'receiver_id' => $myId,
//                         'message' => $aiResponseText
//                     ]);

//                     broadcast(new MessageSent($aiMessage->load('sender')))->toOthers();

//                     return response()->json([
//                         'status' => 'success',
//                         'user_message' => $userMessage->load('sender'),
//                         'ai_message' => $aiMessage->load('sender')
//                     ]);
//                 }
//             }
//         }

//         return response()->json(['status' => 'success', 'user_message' => $userMessage->load('sender')]);
//     }

//     private function cekStatusPesananLokal($userId, $orderId = null) {
//         $query = Transaction::where('user_id', $userId)->latest();
//         if ($orderId) { $query->where('order_id', 'LIKE', '%' . $orderId . '%'); }
//         $transaction = $query->first();

//         if (!$transaction) { return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.']; }

//         $result = [
//             'order_id' => $transaction->order_id,
//             'status_pembayaran' => $transaction->status,
//             'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
//             'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
//         ];
//         return $result;
//     }

//     // 4. Otak Gemini Hybrid
//     private function generateGeminiResponse($userText, $userId) {
//         try {
//             $products = Product::where('status', 'active')->take(15)->get();
//             $dbContext = "DATA PRODUK GYCORA (REAL-TIME):\n";
//             foreach ($products as $p) {
//                 $harga = number_format($p->price, 0, ',', '.');
//                 $dbContext .= "- {$p->name} (Rp {$harga}, Stok: {$p->stock})\n";
//             }

//             $hardcodedKnowledge = "
//             INFO KONTAK: Gycora Essence. WA: 082273736200 | Email: gycora.essence@gmail.com
//             KEBIJAKAN RETUR: Maksimal 3 HARI. Wajib Video Unboxing tanpa edit.
//             ";

//             $systemInstruction = "Kamu adalah Gycora Care, asisten virtual resmi Gycora. Sapa pengguna 'Kak'.
//             TUGAS MUTLAK:
//             1. JIKA pengguna secara eksplisit/jelas meminta bicara dengan ADMIN MANUSIA, atau keluhannya sangat marah/kompleks, WAJIB panggil fungsi 'transfer_to_human'.
//             2. Jika pengguna melacak pesanan/resi, panggil fungsi 'lacak_pesanan_database'.\n" . $hardcodedKnowledge . "\n" . $dbContext;

//             $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
//             $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey;

//             $tools = [
//                 ['functionDeclarations' => [
//                     ['name' => 'lacak_pesanan_database', 'description' => 'Panggil saat melacak pesanan.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['order_id' => ['type' => 'STRING']]]],
//                     ['name' => 'transfer_to_human', 'description' => 'Panggil fungsi ini jika pengguna minta bicara dengan admin asli.']
//                 ]]
//             ];

//             // Riwayat percakapan untuk konteks AI
//             $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
//             $history = Message::where(function($q) use ($userId, $adminIds) {
//                 $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
//             })->orWhere(function($q) use ($userId, $adminIds) {
//                 $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
//             })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

//             $geminiContents = [];
//             $lastRole = '';
//             foreach ($history as $chat) {
//                 if (empty(trim($chat->message))) continue;
//                 $role = $chat->sender_id === $userId ? 'user' : 'model';
//                 if ($role === $lastRole) {
//                     $lastIndex = count($geminiContents) - 1;
//                     $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//                 } else {
//                     $geminiContents[] = ['role' => $role, 'parts' => [['text' => $chat->message]]];
//                     $lastRole = $role;
//                 }
//             }

//             $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

//             $payload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'contents' => $geminiContents, 'tools' => $tools, 'generationConfig' => ['temperature' => 0.4]];

//             $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

//             if ($response->successful()) {
//                 $data = $response->json();
//                 $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

//                 if (isset($parts['functionCall'])) {
//                     $functionCall = $parts['functionCall'];
//                     $functionName = $functionCall['name'];
//                     $args = $functionCall['args'] ?? [];

//                     // EKSEKUSI 1: HANDOFF KE MANUSIA
//                     if ($functionName === 'transfer_to_human') {
//                         Cache::put('chat_mode_' . $userId, 'human', now()->addHours(24));
//                         return "Baik Kak, mohon ditunggu sebentar ya. Saya sudah memanggil tim Admin Manusia kami. Mereka akan segera membalas pesan Kakak langsung di ruang obrolan ini 🙏";
//                     }

//                     // EKSEKUSI 2: LACAK PESANAN
//                     if ($functionName === 'lacak_pesanan_database') {
//                         $hasilDatabase = $this->cekStatusPesananLokal($userId, $args['order_id'] ?? null);

//                         $safeArgs = json_decode(json_encode($args, JSON_FORCE_OBJECT), false);
//                         $safeResponse = json_decode(json_encode(['result' => $hasilDatabase], JSON_FORCE_OBJECT), false);

//                         $geminiContents[] = ['role' => 'model', 'parts' => [['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]]];
//                         $geminiContents[] = ['role' => 'function', 'parts' => [['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]]];

//                         $secondPayload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'tools' => $tools, 'contents' => $geminiContents, 'generationConfig' => ['temperature' => 0.4]];
//                         $secondResponse = Http::timeout(20)->post($url, $secondPayload);

//                         if ($secondResponse->successful()) {
//                             return $secondResponse->json('candidates.0.content.parts.0.text') ?? "Maaf kak, saya gagal menerjemahkan pesanan.";
//                         }
//                     }
//                 }
//                 return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
//             }
//             Log::error('Gemini API Error: ' . $response->body());
//             return "Maaf kak, sistem AI sedang sibuk. Mohon tunggu admin kami membalas ya.";

//         } catch (\Exception $e) {
//             Log::error('Gemini Exception: ' . $e->getMessage());
//             return "Maaf kak, asisten AI sedang offline. Pesan kakak akan dibalas oleh tim kami segera.";
//         }
//     }

//     public function markAsRead($senderId) {
//         $myId = auth()->id();
//         $me = User::find($myId);

//         $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
//         $aiUser = User::where('email', 'ai@gycora.com')->first();
//         if ($aiUser && !in_array($aiUser->id, $adminIds)) $adminIds[] = $aiUser->id;

//         if (!in_array($me->usertype, ['admin', 'superadmin', 'cs'])) {
//             // Pelanggan membaca pesan Solher Care
//             Message::whereIn('sender_id', $adminIds)->where('receiver_id', $myId)->where('is_read', false)->update(['is_read' => true]);
//         } else {
//             // Admin membaca pesan Pelanggan
//             Message::where('sender_id', $senderId)->whereIn('receiver_id', $adminIds)->where('is_read', false)->update(['is_read' => true]);
//         }
//         return response()->json(['status' => 'success']);
//     }
// }

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Product;
use App\Models\Transaction;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
// 👇 [BARU] Wajib import Facade Mail dan Mailable Class
use Illuminate\Support\Facades\Mail;
use App\Mail\ChatMessageNotificationMail;

class ChatController extends Controller
{
    public function getStaffList() {
        $myId = auth()->id();
        $aiUser = User::firstOrCreate(['email' => 'ai@gycora.com'], ['first_name' => 'Gycora', 'last_name' => 'AI Assistant', 'password' => bcrypt('password_rahasia_ai_123'), 'usertype' => 'admin', 'phone' => '00000000000']);
        $mainAdmin = User::where('email', '!=', 'ai@gycora.com')->whereIn('usertype', ['admin', 'superadmin', 'cs'])->first();

        if($mainAdmin) {
            $mainAdmin->first_name = "Gycora";
            $mainAdmin->last_name = "Care";
            $mainAdmin->usertype = "Official Account";

            // Hitung pesan belum terbaca
            $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
            if (!in_array($aiUser->id, $adminIds)) $adminIds[] = $aiUser->id;

            $mainAdmin->unread_count = Message::whereIn('sender_id', $adminIds)->where('receiver_id', $myId)->where('is_read', false)->count();
            return response()->json([$mainAdmin]);
        }
        return response()->json([$aiUser]);
    }

    public function getMessages($userId) {
        $myId = auth()->id();
        $me = User::find($myId);

        // Kumpulkan semua ID Admin, CS, dan Superadmin
        $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();

        // Pastikan AI juga masuk dalam deteksi
        $aiUser = User::where('email', 'ai@gycora.com')->first();
        if ($aiUser && !in_array($aiUser->id, $adminIds)) {
            $adminIds[] = $aiUser->id;
        }

        $isCustomer = !in_array($me->usertype, ['admin', 'superadmin', 'cs']);

        if ($isCustomer) {
            // Logika Pelanggan: Ambil semua pesan antara Pelanggan (saya) dan SELURUH admin/AI
            $messages = Message::where(function($q) use ($myId, $adminIds) {
                $q->where('sender_id', $myId)->whereIn('receiver_id', $adminIds);
            })->orWhere(function($q) use ($myId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $myId);
            })->with('sender')->orderBy('created_at', 'asc')->get();
        } else {
            // Logika Admin: Ambil semua pesan antara Pelanggan tertentu (userId) dan SELURUH admin/AI
            $messages = Message::where(function($q) use ($userId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
            })->orWhere(function($q) use ($userId, $adminIds) {
                $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
            })->with('sender')->orderBy('created_at', 'asc')->get();
        }

        return response()->json($messages);
    }

    public function sendMessage(Request $request) {
        $request->validate(['receiver_id' => 'required|exists:users,id', 'message' => 'required|string']);

        $myId = auth()->id();
        $me = User::find($myId);
        $receiver = User::findOrFail($request->receiver_id);

        $userMessage = Message::create([
            'sender_id' => $myId,
            'receiver_id' => $receiver->id,
            'message' => $request->message
        ]);

        broadcast(new MessageSent($userMessage->load('sender')))->toOthers();

        // =========================================================================
        // 👇 [BARU] LOGIKA PENGIRIMAN EMAIL NOTIFIKASI 👇
        // =========================================================================
        // Jangan kirim notifikasi email ke alamat bot AI
        if ($receiver->email !== 'ai@gycora.com') {
            try {
                // Metode queue() menugaskan pengiriman email ke background job
                Mail::to($receiver->email)->queue(new ChatMessageNotificationMail($me, $userMessage));
            } catch (\Exception $e) {
                // Jika email gagal terkirim (misal karena limit SMTP), chat di React tetap berjalan normal
                Log::error('Gagal mengirim email notifikasi chat: ' . $e->getMessage());
            }
        }
        // =========================================================================

        $isCustomer = !in_array($me->usertype, ['admin', 'superadmin', 'cs']);
        $isReceiverAdmin = in_array($receiver->usertype, ['admin', 'superadmin', 'cs']) || $receiver->email === 'ai@gycora.com';

        // Jika Pelanggan mengirim pesan ke Admin/AI
        if ($isCustomer && $isReceiverAdmin) {

            $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

            if ($chatMode === 'ai') {
                $aiUser = User::where('email', 'ai@gycora.com')->first();
                $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

                if ($aiResponseText) {
                    $aiMessage = Message::create([
                        'sender_id' => $aiUser->id,
                        'receiver_id' => $myId,
                        'message' => $aiResponseText
                    ]);

                    broadcast(new MessageSent($aiMessage->load('sender')))->toOthers();

                    return response()->json([
                        'status' => 'success',
                        'user_message' => $userMessage->load('sender'),
                        'ai_message' => $aiMessage->load('sender')
                    ]);
                }
            }
        }

        return response()->json(['status' => 'success', 'user_message' => $userMessage->load('sender')]);
    }

    private function cekStatusPesananLokal($userId, $orderId = null) {
        $query = Transaction::where('user_id', $userId)->latest();
        if ($orderId) { $query->where('order_id', 'LIKE', '%' . $orderId . '%'); }
        $transaction = $query->first();

        if (!$transaction) { return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.']; }

        $result = [
            'order_id' => $transaction->order_id,
            'status_pembayaran' => $transaction->status,
            'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
            'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
        ];
        return $result;
    }

    private function generateGeminiResponse($userText, $userId) {
        try {
            $products = Product::where('status', 'active')->take(15)->get();
            $dbContext = "DATA PRODUK GYCORA (REAL-TIME):\n";
            foreach ($products as $p) {
                $harga = number_format($p->price, 0, ',', '.');
                $dbContext .= "- {$p->name} (Rp {$harga}, Stok: {$p->stock})\n";
            }

            $hardcodedKnowledge = "
            INFO KONTAK: Gycora Essence. WA: 082273736200 | Email: gycora.essence@gmail.com
            KEBIJAKAN RETUR: Maksimal 3 HARI. Wajib Video Unboxing tanpa edit.
            ";

            $systemInstruction = "Kamu adalah Gycora Care, asisten virtual resmi Gycora. Sapa pengguna 'Kak'.
            TUGAS MUTLAK:
            1. JIKA pengguna secara eksplisit/jelas meminta bicara dengan ADMIN MANUSIA, atau keluhannya sangat marah/kompleks, WAJIB panggil fungsi 'transfer_to_human'.
            2. Jika pengguna melacak pesanan/resi, panggil fungsi 'lacak_pesanan_database'.\n" . $hardcodedKnowledge . "\n" . $dbContext;

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

            $tools = [
                ['functionDeclarations' => [
                    ['name' => 'lacak_pesanan_database', 'description' => 'Panggil saat melacak pesanan.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['order_id' => ['type' => 'STRING']]]],
                    ['name' => 'transfer_to_human', 'description' => 'Panggil fungsi ini jika pengguna minta bicara dengan admin asli.']
                ]]
            ];

            // Riwayat percakapan untuk konteks AI
            $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
            $history = Message::where(function($q) use ($userId, $adminIds) {
                $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
            })->orWhere(function($q) use ($userId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
            })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

            $geminiContents = [];
            $lastRole = '';
            foreach ($history as $chat) {
                if (empty(trim($chat->message))) continue;
                $role = $chat->sender_id === $userId ? 'user' : 'model';
                if ($role === $lastRole) {
                    $lastIndex = count($geminiContents) - 1;
                    $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
                } else {
                    $geminiContents[] = ['role' => $role, 'parts' => [['text' => $chat->message]]];
                    $lastRole = $role;
                }
            }

            $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

            $payload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'contents' => $geminiContents, 'tools' => $tools, 'generationConfig' => ['temperature' => 0.4]];

            $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

                if (isset($parts['functionCall'])) {
                    $functionCall = $parts['functionCall'];
                    $functionName = $functionCall['name'];
                    $args = $functionCall['args'] ?? [];

                    // EKSEKUSI 1: HANDOFF KE MANUSIA
                    if ($functionName === 'transfer_to_human') {
                        Cache::put('chat_mode_' . $userId, 'human', now()->addHours(24));
                        return "Baik Kak, mohon ditunggu sebentar ya. Saya sudah memanggil tim Admin Manusia kami. Mereka akan segera membalas pesan Kakak langsung di ruang obrolan ini 🙏";
                    }

                    // EKSEKUSI 2: LACAK PESANAN
                    if ($functionName === 'lacak_pesanan_database') {
                        $hasilDatabase = $this->cekStatusPesananLokal($userId, $args['order_id'] ?? null);

                        $safeArgs = json_decode(json_encode($args, JSON_FORCE_OBJECT), false);
                        $safeResponse = json_decode(json_encode(['result' => $hasilDatabase], JSON_FORCE_OBJECT), false);

                        $geminiContents[] = ['role' => 'model', 'parts' => [['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]]];
                        $geminiContents[] = ['role' => 'function', 'parts' => [['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]]];

                        $secondPayload = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'tools' => $tools, 'contents' => $geminiContents, 'generationConfig' => ['temperature' => 0.4]];
                        $secondResponse = Http::timeout(20)->post($url, $secondPayload);

                        if ($secondResponse->successful()) {
                            return $secondResponse->json('candidates.0.content.parts.0.text') ?? "Maaf kak, saya gagal menerjemahkan pesanan.";
                        }
                    }
                }
                return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
            }
            Log::error('Gemini API Error: ' . $response->body());
            return "Maaf kak, sistem AI sedang sibuk. Mohon tunggu admin kami membalas ya.";

        } catch (\Exception $e) {
            Log::error('Gemini Exception: ' . $e->getMessage());
            return "Maaf kak, asisten AI sedang offline. Pesan kakak akan dibalas oleh tim kami segera.";
        }
    }

    public function markAsRead($senderId) {
        $myId = auth()->id();
        $me = User::find($myId);

        $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
        $aiUser = User::where('email', 'ai@gycora.com')->first();
        if ($aiUser && !in_array($aiUser->id, $adminIds)) $adminIds[] = $aiUser->id;

        if (!in_array($me->usertype, ['admin', 'superadmin', 'cs'])) {
            // Pelanggan membaca pesan Gycora Care
            Message::whereIn('sender_id', $adminIds)->where('receiver_id', $myId)->where('is_read', false)->update(['is_read' => true]);
        } else {
            // Admin membaca pesan Pelanggan
            Message::where('sender_id', $senderId)->whereIn('receiver_id', $adminIds)->where('is_read', false)->update(['is_read' => true]);
        }
        return response()->json(['status' => 'success']);
    }
}
