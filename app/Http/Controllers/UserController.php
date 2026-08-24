<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // 1. GET: Ambil semua data user (Bisa ditambah pagination/pencarian nanti)
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $users]);
    }

    // 2. POST: Buat user baru (Contoh: Superadmin menambahkan akun Gudang)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'usertype' => ['required', Rule::in(['user','admin','superadmin','gudang','accounting','reseller'])],
            'phone' => 'nullable|string|max:20',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        return response()->json(['status' => 'success', 'message' => 'User berhasil dibuat', 'data' => $user], 201);
    }

    // 3. GET: Ambil detail 1 user
    public function show($id)
    {
        $user = User::findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $user]);
    }

    // 4. PUT/PATCH: Update data user
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'usertype' => ['sometimes', 'required', Rule::in(['user','admin','superadmin','gudang','accounting','reseller'])],
            'phone' => 'nullable|string|max:20',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']); // Jangan update password jika kosong
        }

        $user->update($validated);

        return response()->json(['status' => 'success', 'message' => 'Data user berhasil diperbarui', 'data' => $user]);
    }

    // 5. DELETE: Hapus user
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Proteksi: Superadmin tidak boleh menghapus dirinya sendiri
        if (request()->user()->id === $user->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun Anda sendiri'], 403);
        }

        $user->delete();

        return response()->json(['status' => 'success', 'message' => 'User berhasil dihapus']);
    }
}
