<?php

// namespace App\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Http\Resources\AddressResource;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class AddressController extends Controller
// {
//     // =========================================================================
//     // 1. GET: Ambil Semua Alamat
//     // =========================================================================
//     public function index(Request $request)
//     {
//         // Eloquent otomatis memfilter berdasarkan user_id dan mengurutkan DESC
//         $addresses = $request->user()->addresses()->orderBy('id', 'desc')->get();

//         return AddressResource::collection($addresses);
//     }

//     // =========================================================================
//     // 2. POST: Tambah Alamat
//     // =========================================================================
//     public function store(Request $request)
//     {
//         // Validasi input (bisa dipindah ke FormRequest jika ingin lebih rapi)
//         $validated = $request->validate([
//             'region'             => 'required|string',
//             'first_name_address' => 'required|string',
//             'last_name_address'  => 'required|string',
//             'address_location'   => 'required|string',
//             'city'               => 'required|string',
//             'province'           => 'required|string',
//             'postal_code'        => 'required|string',
//             'location_type'      => 'nullable|string',
//             'latitude'           => 'nullable|string',
//             'longitude'          => 'nullable|string',
//             'is_default'         => 'boolean',
//         ]);

//         $user = $request->user();
//         $isDefault = $request->boolean('is_default', false);

//         // Menggunakan Database Transaction seperti di Golang
//         $address = DB::transaction(function () use ($user, $validated, $isDefault) {

//             // Jika user set alamat ini sebagai default, reset alamat lainnya menjadi false (0)
//             if ($isDefault) {
//                 $user->addresses()->update(['is_default' => false]);
//             }

//             // Simpan alamat baru (otomatis mengisi user_id melalui relasi)
//             $validated['is_default'] = $isDefault;
//             return $user->addresses()->create($validated);
//         });

//         return response()->json(new AddressResource($address), 201);
//     }

//     // =========================================================================
//     // 3. PUT: Update Alamat
//     // =========================================================================
//     public function update(Request $request, $id)
//     {
//         $user = $request->user();

//         // Memastikan alamat ini ada dan milik user yang sedang login
//         $address = $user->addresses()->findOrFail($id);

//         $validated = $request->validate([
//             'region'             => 'sometimes|required|string',
//             'first_name_address' => 'sometimes|required|string',
//             'last_name_address'  => 'sometimes|required|string',
//             'address_location'   => 'sometimes|required|string',
//             'city'               => 'sometimes|required|string',
//             'province'           => 'sometimes|required|string',
//             'postal_code'        => 'sometimes|required|string',
//             'location_type'      => 'nullable|string',
//             'latitude'           => 'nullable|string',
//             'longitude'          => 'nullable|string',
//             'is_default'         => 'boolean',
//         ]);

//         $isDefault = $request->boolean('is_default', false);

//         DB::transaction(function () use ($user, $address, $validated, $isDefault) {

//             // Reset alamat lain jika alamat yang sedang diedit ini dijadikan default
//             if ($isDefault) {
//                 $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
//             }

//             $validated['is_default'] = $isDefault;
//             $address->update($validated);
//         });

//         return response()->json([
//             'message' => 'Address updated successfully'
//         ], 200);
//     }

//     // =========================================================================
//     // 4. DELETE: Hapus Alamat
//     // =========================================================================
//     public function destroy(Request $request, $id)
//     {
//         // Temukan dan pastikan itu milik user (akan otomatis error 404 jika bukan miliknya)
//         $address = $request->user()->addresses()->findOrFail($id);

//         $address->delete();

//         return response()->json([
//             'message' => 'Address successfully deleted'
//         ], 200);
//     }
// }

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class AddressController extends Controller
{
    // =========================================================================
    // HELPER: Format Response API (Pengganti AddressResource)
    // =========================================================================
    private function formatAddress($address)
    {
        return [
            'id' => $address->id,
            'receiver' => [
                'first_name' => $address->first_name_address,
                'last_name'  => $address->last_name_address,
                'full_name'  => trim($address->first_name_address . ' ' . $address->last_name_address),
            ],
            'details' => [
                'region'           => $address->region,
                'address_location' => $address->address_location,
                'type'             => $address->location_type ?: 'other',
                'city'             => $address->city,
                'province'         => $address->province,
                'postal_code'      => $address->postal_code,
                'latitude'         => $address->latitude,
                'longitude'        => $address->longitude,
            ],
            'is_default' => (bool) $address->is_default,
            'created_at' => $address->created_at ? $address->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    // =========================================================================
    // 1. GET: Ambil Semua Alamat
    // =========================================================================
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->orderBy('id', 'desc')->get();

        // Menggunakan method map() dari koleksi Laravel untuk memformat setiap alamat
        $formattedAddresses = $addresses->map(function ($address) {
            return $this->formatAddress($address);
        });

        // Mengembalikan dalam bentuk 'data' array agar strukturnya sama persis dengan Resource Collection
        return response()->json([
            'data' => $formattedAddresses
        ], 200);
    }

    // =========================================================================
    // 2. POST: Tambah Alamat
    // =========================================================================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'region'             => 'required|string',
            'first_name_address' => 'required|string',
            'last_name_address'  => 'required|string',
            'address_location'   => 'required|string',
            'city'               => 'required|string',
            'province'           => 'required|string',
            'postal_code'        => 'required|string',
            'location_type'      => 'nullable|string',
            'latitude'           => 'nullable|string',
            'longitude'          => 'nullable|string',
            'is_default'         => 'boolean',
        ]);

        $user = $request->user();
        $isDefault = $request->boolean('is_default', false);

        $address = DB::transaction(function () use ($user, $validated, $isDefault) {

            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            $validated['is_default'] = $isDefault;
            return $user->addresses()->create($validated);
        });

        // Panggil fungsi formatAddress untuk respon tunggal
        return response()->json($this->formatAddress($address), 201);
    }

    // =========================================================================
    // 3. PUT: Update Alamat
    // =========================================================================
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $address = $user->addresses()->findOrFail($id);

        $validated = $request->validate([
            'region'             => 'sometimes|required|string',
            'first_name_address' => 'sometimes|required|string',
            'last_name_address'  => 'sometimes|required|string',
            'address_location'   => 'sometimes|required|string',
            'city'               => 'sometimes|required|string',
            'province'           => 'sometimes|required|string',
            'postal_code'        => 'sometimes|required|string',
            'location_type'      => 'nullable|string',
            'latitude'           => 'nullable|string',
            'longitude'          => 'nullable|string',
            'is_default'         => 'boolean',
        ]);

        $isDefault = $request->boolean('is_default', false);

        DB::transaction(function () use ($user, $address, $validated, $isDefault) {
            if ($isDefault) {
                $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            $validated['is_default'] = $isDefault;
            $address->update($validated);
        });

        return response()->json([
            'message' => 'Address updated successfully'
        ], 200);
    }

    // =========================================================================
    // 4. DELETE: Hapus Alamat
    // =========================================================================
    public function destroy(Request $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->delete();

        return response()->json([
            'message' => 'Address successfully deleted'
        ], 200);
    }
}
