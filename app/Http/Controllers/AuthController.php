<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // =========================================================================
    // 1. REGISTER USER
    // =========================================================================
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $isSubscribed = false;

        DB::transaction(function () use ($validated, &$isSubscribed, &$user) {
            // Cek apakah email ini sudah pernah subscribe saat menjadi Guest
            // Asumsi Anda memiliki model Subscriber atau tabel subscribers
            $subscriber = DB::table('subscribers')->where('email', $validated['email'])->first();

            if ($subscriber) {
                $isSubscribed = true;
                // Tandai bahwa dia kini Registered
                DB::table('subscribers')->where('id', $subscriber->id)->update(['is_registered' => 1]);
            }

            // Buat User baru
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'usertype' => 'user', // Default usertype
                'is_subscribed' => $isSubscribed,
            ]);
        });

        return response()->json([
            'message' => 'User berhasil didaftarkan',
            'user' => $user,
        ], 201);
    }

    // =========================================================================
    // 2. LOGIN USER (Hanya role 'user' yang diizinkan)
    // =========================================================================
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'recaptcha_token' => 'required|string' // Wajibkan token dari frontend
        ]);

        // VERIFIKASI RECAPTCHA
        if (!$this->verifyRecaptcha($request->recaptcha_token)) {
            return response()->json([
                'message' => 'Verifikasi keamanan reCAPTCHA gagal. Terdeteksi sebagai aktivitas bot.'
            ], 403);
        }

        $user = User::where('email', $request->email)->first();

        // Cek jika user tidak ditemukan, password salah, atau usertype BUKAN 'user'
        // if (! $user || ! Hash::check($request->password, $user->password) || $user->usertype !== 'user' || $user->usertype !== 'reseller') {
        //     throw ValidationException::withMessages([
        //         'email' => ['Email atau Password salah.'],
        //     ]);
        // }

        // Cek jika user tidak ditemukan, password salah
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau Password salah.'],
            ]);
        }

        // Cek apakah user memiliki hak akses sebagai pelanggan atau mitra (bukan admin)
        // Gunakan in_array agar lebih mudah dibaca dan diekspansi kelak
        if (! in_array($user->usertype, ['user', 'reseller'])) {
            throw ValidationException::withMessages([
                'email' => ['Akses ditolak. Akun ini tidak memiliki hak akses sebagai pelanggan.'],
            ]);
        }

        // Generate Token via Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login Berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // =========================================================================
    // 3. LOGIN ADMIN (Hanya role manajerial yang diizinkan)
    // =========================================================================
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'recaptcha_token' => 'required|string' // Wajibkan token
        ]);

        // VERIFIKASI RECAPTCHA
        if (!$this->verifyRecaptcha($request->recaptcha_token)) {
            return response()->json([
                'message' => 'Akses diblokir. Verifikasi reCAPTCHA gagal.'
            ], 403);
        }

        $user = User::where('email', $request->email)->first();

        $allowedAdminRoles = ['admin', 'superadmin', 'gudang', 'accounting', 'cs'];

        // Cek autentikasi dan otorisasi role
        if (! $user || ! Hash::check($request->password, $user->password) || ! in_array($user->usertype, $allowedAdminRoles)) {
            return response()->json([
                'message' => 'Akses ditolak. Email/Password salah atau Anda tidak memiliki akses ke panel ini.',
            ], 401);
        }

        $token = $user->createToken('admin_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login Berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // =========================================================================
    // 4. AMBIL SEMUA USER (Khusus Pelanggan)
    // =========================================================================
    // public function getAllUsers()
    // {
    //     // Mengambil user yang bukan admin/staf
    //     $users = User::whereIn('usertype', ['user', 'reseller'])
    //         ->orderBy('id', 'desc')
    //         ->get(['id', 'first_name', 'last_name', 'email', 'usertype', 'is_subscribed', 'created_at']);

    //     // Jika Anda ingin memastikan format tanggal sama persis seperti respons Golang,
    //     // Anda bisa melakukan map pada collection, tapi default JSON Eloquent biasanya sudah cukup baik.
    //     // Format default created_at Eloquent: "2024-05-12T10:00:00.000000Z"

    //     return response()->json($users);
    // }

    public function getAllUsers() {
        $adminIds = User::whereIn('usertype', ['admin', 'superadmin', 'cs'])->pluck('id')->toArray();
        $aiUser = User::where('email', 'ai@gycora.com')->first();
        if ($aiUser && !in_array($aiUser->id, $adminIds)) $adminIds[] = $aiUser->id;

        $users = User::whereIn('usertype', ['user', 'reseller'])
            ->withCount(['messages as unread_count' => function ($query) use ($adminIds) {
                $query->where('is_read', false)->whereIn('receiver_id', $adminIds);
            }])
            ->latest()->get();

        return response()->json(['data' => $users], 200);
    }

    // =========================================================================
    // 5. UPDATE PROFIL USER
    // =========================================================================
    public function updateProfile(Request $request)
    {
        $user = $request->user(); // Mendapatkan user yang sedang login via Sanctum

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            // Validasi email: pastikan format email benar dan unik di tabel users, KECUALI untuk ID user ini sendiri
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
        ], [
            // Kustomisasi pesan error agar sama seperti logika Golang Anda
            'email.unique' => 'Email sudah digunakan oleh akun lain',
        ]);

        // Eloquent secara otomatis menangani `updated_at`
        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => $user, // Mengembalikan objek user yang sudah terupdate
        ]);
    }

    public function updateAdminProfileInfo(Request $request)
    {
        $admin = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$admin->id,
            'phone' => 'nullable|string|max:20', // [BARU] Tambahkan validasi phone
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // [BARU] Sertakan phone saat update
        $admin->update($request->only('first_name', 'last_name', 'email', 'phone'));

        return response()->json([
            'message' => 'Admin profile updated successfully',
            'admin' => $admin,
        ]);
    }

    // 1. FUNGSI BARU UNTUK S3 PRE-SIGNED URL PROFIL
    public function getProfilePresignedUrl(Request $request)
    {
        $request->validate([
            'extension' => 'required|string',
            'content_type' => 'required|string',
        ]);

        $filename = 'profiles/'.Str::random(40).'.'.$request->extension;

        $uploadResponse = Storage::disk('s3')->temporaryUploadUrl(
            $filename,
            now()->addMinutes(15),
            [
                'ContentType' => $request->content_type,
                'ACL' => 'public-read', // Penting agar foto profil bisa diakses publik
            ]
        );

        $fileUrl = env('AWS_URL').'/'.$filename;

        return response()->json([
            'upload_url' => $uploadResponse['url'],
            'upload_headers' => $uploadResponse['headers'],
            'file_url' => $fileUrl,
        ]);
    }

    // 2. UBAH FUNGSI UPDATE GAMBAR
    public function updateAdminImage(Request $request)
    {
        // Sekarang kita hanya menerima string URL, BUKAN file mentah
        $request->validate([
            'image_url' => 'required|url',
        ]);

        $admin = $request->user();

        try {
            // Jika ada foto lama di S3, hapus dulu agar tidak menumpuk
            if ($admin->profile_image) {
                // Ekstrak path setelah domain AWS
                $oldKey = str_replace(env('AWS_URL').'/', '', $admin->profile_image);

                // Pastikan key-nya benar-benar ada dan tidak kosong
                if ($oldKey && $oldKey !== $admin->profile_image) {
                    Storage::disk('s3')->delete($oldKey);
                }
            }

            // Simpan URL penuh S3 ke database
            $admin->profile_image = $request->image_url;
            $admin->save();

            return response()->json([
                'message' => 'Admin photo updated',
                'admin' => $admin->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update admin photo: '.$e->getMessage(),
            ], 500);
        }
    }

    public function updateAdminPassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $admin = $request->user();

        if (! Hash::check($request->old_password, $admin->password)) {
            return response()->json([
                'message' => 'Old password does not match',
            ], 401);
        }

        $admin->password = Hash::make($request->password);
        $admin->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    // 1. Update Nama & Email
    public function updateProfileInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        $user->update($request->only('first_name', 'last_name', 'email', 'phone'));

        return response()->json(['message' => 'Info profil diperbarui', 'user' => $user]);
    }

    // public function updateImage(Request $request)
    // {
    //     Log::info('Update profile image started', [
    //         'user_id' => $request->user()->id
    //     ]);

    //     $request->validate([
    //         'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
    //     ]);

    //     $user = $request->user();

    //     try {
    //         // Jika ada foto lama
    //         if ($user->profile_image) {
    //             $oldPath = 'profiles/' . basename($user->profile_image);

    //             Log::info('Deleting old profile image', [
    //                 'user_id' => $user->id,
    //                 'old_path' => $oldPath
    //             ]);

    //             Storage::disk('s3')->delete($oldPath);
    //         }

    //         // Upload foto baru
    //         $path = $request->file('image')->store('profiles', [
    //             'disk' => 's3',
    //             'visibility' => 'public'
    //         ]);

    //         Log::info('New profile image uploaded', [
    //             'user_id' => $user->id,
    //             'new_path' => $path
    //         ]);

    //         $user->profile_image = Storage::disk('s3')->url($path);
    //         $user->save();

    //         $user = $user->fresh();

    //         Log::info('Profile image updated successfully', [
    //             'user_id' => $user->id,
    //             'profile_image_url' => $user->profile_image
    //         ]);

    //         return response()->json([
    //             'message' => 'Foto profil diperbarui',
    //             'user' => $user
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to update profile image', [
    //             'user_id' => $user->id ?? null,
    //             'error_message' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'message' => 'Gagal memperbarui foto profil'
    //         ], 500);
    //     }
    // }

    // public function updateImage(Request $request)
    // {
    //     Log::info('Update profile image started', [
    //         'user_id' => $request->user()->id
    //     ]);

    //     $request->validate([
    //         'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
    //     ]);

    //     $user = $request->user();

    //     try {
    //         // [PERBAIKAN] Jika ada foto lama, hapus dari Local Storage
    //         if ($user->profile_image) {
    //             // Bersihkan URL agar hanya menyisakan path relatifnya saja
    //             $oldPath = str_replace(url(Storage::url('')), '', $user->profile_image);
    //             $oldPath = ltrim(str_replace('/storage/', '', $oldPath), '/');

    //             Log::info('Deleting old profile image', [
    //                 'user_id' => $user->id,
    //                 'old_path' => $oldPath
    //             ]);

    //             Storage::disk('public')->delete($oldPath);
    //         }

    //         // [PERBAIKAN] Upload foto baru ke Local Storage (disk 'public' -> storage/app/public/profiles)
    //         $path = $request->file('image')->store('profiles', 'public');

    //         Log::info('New profile image uploaded', [
    //             'user_id' => $user->id,
    //             'new_path' => $path
    //         ]);

    //         // [PERBAIKAN] Karena kita tidak memakai Accessor di User Model, kita simpan URL penuhnya langsung
    //         $user->profile_image = url(Storage::url($path));
    //         $user->save();

    //         $user = $user->fresh();

    //         Log::info('Profile image updated successfully', [
    //             'user_id' => $user->id,
    //             'profile_image_url' => $user->profile_image
    //         ]);

    //         return response()->json([
    //             'message' => 'Foto profil diperbarui',
    //             'user' => $user
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to update profile image', [
    //             'user_id' => $user->id ?? null,
    //             'error_message' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'message' => 'Gagal memperbarui foto profil'
    //         ], 500);
    //     }
    // }

    public function updateImage(Request $request)
    {
        Log::info('Update profile image started', [
            'user_id' => $request->user()->id
        ]);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        $user = $request->user();

        try {
            // [PERBAIKAN] Logika penghapusan foto lama yang lebih tangguh
            // Memastikan kita hanya mencoba menghapus file lokal (yang memiliki '/storage/' di URL-nya)
            if ($user->profile_image && str_contains($user->profile_image, '/storage/')) {

                // Ekstrak nama file/path murni dari URL, contoh: "profiles/namafoto.png"
                $oldPath = explode('/storage/', $user->profile_image)[1] ?? null;

                if ($oldPath) {
                    Log::info('Deleting old profile image', [
                        'user_id' => $user->id,
                        'old_path' => $oldPath
                    ]);
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Upload foto baru ke Local Storage (disk 'public' -> storage/app/public/profiles)
            $path = $request->file('image')->store('profiles', 'public');

            Log::info('New profile image uploaded', [
                'user_id' => $user->id,
                'new_path' => $path
            ]);

            // [PERBAIKAN UTAMA] Gunakan asset() agar secara mutlak menunjuk ke domain server Anda saat ini
            // Hasilnya akan menjadi: https://domain-anda.com/storage/profiles/xxx.png
            $user->profile_image = asset('storage/' . $path);
            $user->save();

            $user = $user->fresh();

            Log::info('Profile image updated successfully', [
                'user_id' => $user->id,
                'profile_image_url' => $user->profile_image
            ]);

            return response()->json([
                'message' => 'Foto profil diperbarui',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update profile image', [
                'user_id' => $user->id ?? null,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Gagal memperbarui foto profil'
            ], 500);
        }
    }

    // 3. Update Password
    public function updatePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'Password lama tidak sesuai'], 401);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password berhasil diubah']);
    }

    // Ambil semua daftar user biasa
    // public function getAllUsers()
    // {
    //     // Mengambil user dengan usertype 'user' saja
    //     $users = User::where('usertype', 'user')->latest()->get(); //
    //     return response()->json($users, 200);
    // }

    // Ambil detail satu user beserta alamatnya
    public function getUserDetail($id)
    {
        // Memuat user beserta relasi addresses yang sudah kita buat sebelumnya
        $user = User::with('addresses')->findOrFail($id); //
        return response()->json($user, 200);
    }

    // public function updateAdminPassword(Request $request)
    // {
    //     $request->validate([
    //         'old_password' => 'required',
    //         'password' => 'required|string|min:8|confirmed',
    //     ]);

    //     $admin = $request->user();

    //     if (!Hash::check($request->old_password, $admin->password)) {
    //         return response()->json([
    //             'message' => 'Old password does not match'
    //         ], 401);
    //     }

    //     $admin->password = Hash::make($request->password);
    //     $admin->save();

    //     return response()->json([
    //         'message' => 'Password updated successfully'
    //     ]);
    // }

    public function toggleMembership(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'is_membership' => 'required|boolean'
        ]);

        $user->update([
            'is_membership' => $request->is_membership
        ]);

        return response()->json(['user' => $user, 'message' => 'Membership status updated!']);
    }

    // --- 1. MENGIRIM KODE OTP KE EMAIL ---
    public function sendResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Email address not found in our system.'], 404);
        }

        // Hapus kode lama jika ada
        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        // Buat 6-digit angka random
        $code = sprintf("%06d", mt_rand(1, 999999));

        DB::table('password_reset_codes')->insert([
            'email' => $request->email,
            'code' => Hash::make($code), // Enkripsi kode di DB untuk keamanan
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now()
        ]);

        try {
            Mail::to($request->email)->send(new ResetPasswordCodeMail($code));
            return response()->json(['message' => 'Verification code sent to your email.']);
        } catch (\Exception $e) {
            Log::error('Failed to send reset code: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send email. Please try again later.'], 500);
        }
    }

    // --- 2. MEMVALIDASI KODE OTP ---
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6'
        ]);

        $resetData = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->first();

        if (!$resetData) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 400);
        }

        if (Carbon::now()->greaterThan($resetData->expires_at)) {
            DB::table('password_reset_codes')->where('email', $request->email)->delete();
            return response()->json(['message' => 'Verification code has expired.'], 400);
        }

        if (!Hash::check($request->code, $resetData->code)) {
            return response()->json(['message' => 'Incorrect verification code.'], 400);
        }

        return response()->json(['message' => 'Code verified successfully.']);
    }

    // --- 3. MERESET PASSWORD BERDASARKAN OTP YANG VALID ---
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed'
        ]);

        $resetData = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->first();

        if (!$resetData || !Hash::check($request->code, $resetData->code) || Carbon::now()->greaterThan($resetData->expires_at)) {
            return response()->json(['message' => 'Invalid session or code expired.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Hapus token reset setelah sukses digunakan
        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password has been successfully reset.']);
    }

    // ====================================================================
    // FUNGSI FORGOT PASSWORD KHUSUS ADMIN
    // ====================================================================
    public function adminSendResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // [PERBAIKAN] Cek apakah email ini milik staf internal (bukan pelanggan biasa)
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();

        if (!$admin) {
            return response()->json(['message' => 'Alamat email tidak ditemukan atau tidak memiliki izin akses.'], 404);
        }

        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        $code = sprintf("%06d", mt_rand(1, 999999));

        DB::table('password_reset_codes')->insert([
            'email' => $request->email,
            'code' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now()
        ]);

        try {
            Mail::to($request->email)->send(new \App\Mail\ResetPasswordCodeMail($code));
            return response()->json(['message' => 'Kode verifikasi telah dikirim ke email Anda.']);
        } catch (\Exception $e) {
            Log::error('Failed to send admin reset code: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email. Silakan coba lagi nanti.'], 500);
        }
    }

    public function adminVerifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6'
        ]);

        // [PERBAIKAN] Validasi staf
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();
        if (!$admin) return response()->json(['message' => 'Akses ditolak.'], 403);

        $resetData = DB::table('password_reset_codes')->where('email', $request->email)->first();

        if (!$resetData) {
            return response()->json(['message' => 'Kode verifikasi tidak valid atau telah kedaluwarsa.'], 400);
        }

        if (Carbon::now()->greaterThan($resetData->expires_at)) {
            DB::table('password_reset_codes')->where('email', $request->email)->delete();
            return response()->json(['message' => 'Kode verifikasi telah kedaluwarsa.'], 400);
        }

        if (!Hash::check($request->code, $resetData->code)) {
            return response()->json(['message' => 'Kode verifikasi salah.'], 400);
        }

        return response()->json(['message' => 'Kode berhasil diverifikasi.']);
    }

    public function adminResetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed'
        ]);

        // [PERBAIKAN] Validasi staf
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();
        if (!$admin) return response()->json(['message' => 'Akses ditolak.'], 403);

        $resetData = DB::table('password_reset_codes')->where('email', $request->email)->first();

        if (!$resetData || !Hash::check($request->code, $resetData->code) || Carbon::now()->greaterThan($resetData->expires_at)) {
            return response()->json(['message' => 'Sesi tidak valid atau kode kedaluwarsa.'], 400);
        }

        $admin->password = Hash::make($request->password);
        $admin->save();

        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Kata sandi berhasil disetel ulang.']);
    }

    // Helper reCAPTCHA v3
    private function verifyRecaptcha($token)
    {
        // Hindari verifikasi jika sedang di environment testing
        if (app()->environment('testing')) return true;
        if (!$token) return false;

        $secretKey = env('RECAPTCHA_SECRET_KEY'); // Pastikan ini ada di .env Laravel
        $response = \Illuminate\Support\Facades\Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
        ]);

        $result = $response->json();

        // Cek success dan pastikan score (skor keamanan bot) >= 0.5
        // Score 1.0 (Pasti Manusia), 0.0 (Pasti Bot)
        if (isset($result['success']) && $result['success'] == true) {
             if (isset($result['score']) && $result['score'] >= 0.5) {
                 return true;
             }
        }
        return false;
    }

    // ====================================================================
    // FUNGSI BARU: SILENT TOKEN REFRESH
    // ====================================================================
    public function refreshToken(Request $request)
    {
        $user = $request->user();

        // 1. Cabut/Hapus token yang saat ini sedang dipakai (kedaluwarsa/hampir kedaluwarsa)
        $user->currentAccessToken()->delete();

        // 2. Terbitkan token baru
        $newToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Token berhasil diperbarui secara silent',
            'access_token' => $newToken,
            'user' => $user
        ], 200);
    }
}
