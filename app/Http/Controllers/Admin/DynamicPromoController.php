<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicPromo;
use Illuminate\Http\Request;

class DynamicPromoController extends Controller
{
    // Tampilkan semua promo
    public function index()
    {
        return response()->json(DynamicPromo::latest()->get());
    }

    // Simpan promo baru
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'banner_badge' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'rules' => 'required|array', // Validasi tipe array (JSON)
        ]);

        $promo = DynamicPromo::create($validated);

        return response()->json([
            'message' => 'Promo berhasil dibuat!',
            'data' => $promo
        ], 201);
    }

    // Ambil 1 promo untuk diedit
    public function show($id)
    {
        return response()->json(DynamicPromo::findOrFail($id));
    }

    // Update promo
    public function update(Request $request, $id)
    {
        $promo = DynamicPromo::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'banner_badge' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'rules' => 'required|array',
        ]);

        $promo->update($validated);

        return response()->json([
            'message' => 'Promo berhasil diperbarui!',
            'data' => $promo
        ]);
    }

    // Hapus promo
    public function destroy($id)
    {
        DynamicPromo::findOrFail($id)->delete();
        return response()->json(['message' => 'Promo berhasil dihapus!']);
    }
}