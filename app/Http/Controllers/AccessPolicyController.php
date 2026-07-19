<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AccessPolicyController extends Controller
{
    // Kita simpan konfigurasinya di storage/app/access_policies.json
    private $filePath = 'access_policies.json';

    public function index()
    {
        // Jika file belum ada, kembalikan array kosong sebagai default
        if (!Storage::exists($this->filePath)) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'admin' => [],
                    'gudang' => [],
                    'accounting' => [],
                    'cs' => [],
                ]
            ]);
        }

        // Jika ada, baca dan kirim ke frontend
        $policies = json_decode(Storage::get($this->filePath), true);
        return response()->json(['status' => 'success', 'data' => $policies]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'policies' => 'required|array'
        ]);

        // Timpa file lama dengan konfigurasi yang baru dari Superadmin
        Storage::put($this->filePath, json_encode($request->policies));

        return response()->json(['status' => 'success', 'message' => 'Policies updated']);
    }
}
