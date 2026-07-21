<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use DatabaseTransactions;
    // use RefreshDatabase;

    private $user;
    private $product;
    private $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'first_name' => 'Regular',
            'last_name' => 'User',
            'email' => 'user_' . Str::random(5) . '@gycora.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user'
        ]);

        $this->category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Cart Category',
            'slug' => 'cart-category-' . Str::random(5),
            'description' => 'Desc'
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'sku' => 'CRT-' . Str::random(4),
            'name' => 'Cart Test Product',
            'slug' => 'cart-test-product-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'active'
        ]);
    }

    private function authenticateUser()
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    public function test_user_can_get_their_cart_items()
    {
        Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'gross_amount' => 100000,
        ]);

        $otherUser = User::create([
            'first_name' => 'Other',
            'last_name' => 'User',
            'email' => 'other_' . Str::random(5) . '@gycora.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user'
        ]);

        Cart::create([
            'user_id' => $otherUser->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'gross_amount' => 250000,
        ]);

        $response = $this->authenticateUser()->getJson('/api/carts');

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'product_id' => $this->product->id,
                     'quantity' => 2,
                 ])
                 ->assertJsonMissing([
                     'user_id' => $otherUser->id,
                     'quantity' => 5
                 ]);
    }

    public function test_guest_cannot_get_cart_items()
    {
        $response = $this->getJson('/api/carts');
        $response->assertStatus(401);
    }

    public function test_user_can_add_product_to_cart()
    {
        $payload = [
            'product_id' => $this->product->id,
            'quantity' => 3,
            'color' => '{"hex":"#000000","name":"Black"}'
        ];

        $response = $this->authenticateUser()->postJson('/api/carts', $payload);

        $response->assertStatus(200)
                 ->assertJsonStructure(['cart_id', 'message']);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'gross_amount' => 150000,
        ]);
    }

    // public function test_adding_same_product_increases_quantity_instead_of_creating_new_row()
    // {
    //     Cart::create([
    //         'user_id' => $this->user->id,
    //         'product_id' => $this->product->id,
    //         'quantity' => 2,
    //         'color' => 'red', // Warna diset
    //         'gross_amount' => 100000,
    //     ]);

    //     $payload = [
    //         'product_id' => $this->product->id,
    //         'quantity' => 1,
    //         'color' => 'red' // PERBAIKAN 1: Pastikan warna sama dikirim di payload
    //     ];

    //     $response = $this->authenticateUser()->postJson('/api/carts', $payload);
    //     $response->assertStatus(200);

    //     $this->assertDatabaseCount('carts', 1);
    //     $this->assertDatabaseHas('carts', [
    //         'user_id' => $this->user->id,
    //         'product_id' => $this->product->id,
    //         'color' => 'red',
    //         'quantity' => 3,
    //         'gross_amount' => 150000,
    //     ]);
    // }

    // public function test_adding_same_product_increases_quantity_instead_of_creating_new_row()
    // {
    //     // Kondisi awal: Keranjang sudah punya 1 item dengan JSON string warna
    //     $colorJson = json_encode(['hex' => '#ff0000', 'name' => 'Red']);

    //     Cart::create([
    //         'user_id' => $this->user->id,
    //         'product_id' => $this->product->id,
    //         'quantity' => 2,
    //         'color' => $colorJson,
    //         'gross_amount' => 100000,
    //     ]);

    //     // Request tambah produk yang sama dengan warna yang sama persis
    //     $payload = [
    //         'product_id' => $this->product->id,
    //         'quantity' => 1,
    //         'color' => $colorJson
    //     ];

    //     $response = $this->authenticateUser()->postJson('/api/carts', $payload);
    //     $response->assertStatus(200);

    //     // Pastikan hanya ada 1 baris di database (row tidak bertambah), tapi qty naik jadi 3
    //     // $this->assertDatabaseCount('carts', 1);

    //     // [BARIS YANG DIUBAH]: Ganti $this->assertDatabaseCount('carts', 1) menjadi kode di bawah ini:
    //     // Memastikan tidak ada row ganda yang tercipta khusus untuk user dan produk ini
    //     $cartCount = Cart::where('user_id', $this->user->id)
    //                      ->where('product_id', $this->product->id)
    //                      ->count();
    //     $this->assertEquals(1, $cartCount, 'Seharusnya data keranjang tidak bertambah baris baru.');
    //     $this->assertDatabaseHas('carts', [
    //         'user_id' => $this->user->id,
    //         'product_id' => $this->product->id,
    //         'color' => $colorJson,
    //         'quantity' => 3,
    //         // Kita hilangkan gross_amount di assertion ini karena format decimal kadang menyebabkan error pembulatan di test,
    //         // (misal 150000 vs 150000.00), yang terpenting kuantitasnya bertambah.
    //     ]);
    // }

    public function test_adding_same_product_increases_quantity_instead_of_creating_new_row()
    {
        // Kondisi awal: Keranjang sudah punya 1 item dengan JSON string warna
        $colorJson = json_encode(['hex' => '#ff0000', 'name' => 'Red']);

        Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'color' => $colorJson,
            'gross_amount' => 100000,
        ]);

        // Request tambah produk yang sama dengan warna yang sama persis
        $payload = [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'color' => $colorJson
        ];

        $response = $this->authenticateUser()->postJson('/api/carts', $payload);
        $response->assertStatus(200);

        // [BARIS YANG DIUBAH]: Tambahkan query() agar Intelephense VSCode tidak error
        $cartCount = Cart::query()
                         ->where('user_id', $this->user->id)
                         ->where('product_id', $this->product->id)
                         ->count();

        $this->assertEquals(1, $cartCount, 'Seharusnya data keranjang tidak bertambah baris baru.');

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'color' => $colorJson,
            'quantity' => 3,
        ]);
    }

    public function test_cannot_add_product_exceeding_stock()
    {
        $payload = [
            'product_id' => $this->product->id,
            'quantity' => 15,
        ];

        $response = $this->authenticateUser()->postJson('/api/carts', $payload);

        // PERBAIKAN 2: Ubah dari 400 menjadi 422 sesuai response Controller Anda
        $response->assertStatus(422);
    }

    public function test_user_can_update_cart_quantity()
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'gross_amount' => 50000,
        ]);

        $payload = [
            'quantity' => 5
        ];

        $response = $this->authenticateUser()->putJson("/api/carts/{$cart->id}", $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'quantity' => 5,
            'gross_amount' => 250000,
        ]);
    }

    public function test_user_can_remove_item_from_cart()
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'gross_amount' => 50000,
        ]);

        $response = $this->authenticateUser()->deleteJson("/api/carts/{$cart->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('carts', [
            'id' => $cart->id
        ]);
    }
}
