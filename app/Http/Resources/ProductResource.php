<?php

// namespace App\Http\Resources;

// use Illuminate\Http\Request;
// use Illuminate\Http\Resources\Json\JsonResource;

// class ProductResource extends JsonResource
// {
//     public function toArray(Request $request): array
//     {
//         return [
//             'id'            => $this->id,
//             'category_id'   => $this->category_id,
//             // Mengambil nama kategori langsung dari relasi Eloquent
//             'category_name' => $this->whenLoaded('category', function () {
//                 return $this->category->name;
//             }),
//             'sku'           => $this->sku,
//             'name'          => $this->name,
//             'slug'          => $this->slug,
//             'description'   => $this->description,
//             'benefits'      => $this->benefits,
//             'price'         => (float) $this->price,
//             'stock'         => (int) $this->stock,
//             'image_url'     => $this->image_url, // Akan otomatis memanggil Mutator di Model
//         ];
//     }
// }

    // namespace App\Http\Resources;

    // use Illuminate\Http\Request;
    // use Illuminate\Http\Resources\Json\JsonResource;

    // class ProductResource extends JsonResource
    // {
    //     public function toArray(Request $request): array
    //     {
    //         return [
    //             'id'            => $this->id,
    //             'category_id'   => $this->category_id,
    //             // Mengambil nama kategori langsung dari relasi Eloquent
    //             'category_name' => $this->whenLoaded('category', function () {
    //                 return $this->category->name;
    //             }),
    //             'sku'           => $this->sku,
    //             'name'          => $this->name,
    //             'slug'          => $this->slug,
    //             'description'   => $this->description,
    //             'benefits'      => $this->benefits,
    //             'price'         => (float) $this->price,
    //             'discount_price'=> $this->discount_price !== null ? (float) $this->discount_price : null, // Pastikan nullable ditangani
    //             'stock'         => (int) $this->stock,
    //             'image_url'     => $this->image_url, // Akan otomatis memanggil Mutator di Model
    //         ];
    //     }
    // }
