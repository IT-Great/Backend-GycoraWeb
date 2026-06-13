<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    // Menggunakan DatabaseTransactions agar aman untuk MySQL Clever Cloud
    use DatabaseTransactions, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function authenticateAdmin()
    {
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Gycora',
            'email' => 'admin_' . Str::random(5) . '@gycora.com',
            'password' => bcrypt('password123'),
            'usertype' => 'admin',
        ]);

        return $this->actingAs($admin, 'sanctum');
    }

    public function test_can_get_all_active_products()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Hair Care',
            'slug' => 'hair-care-' . Str::random(5),
            'description' => 'Test Description',
        ]);

        Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-ACTIVE-' . Str::random(3),
            'name' => 'Active Product',
            'slug' => 'active-product-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'active',
        ]);

        Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-INACTIVE-' . Str::random(3),
            'name' => 'Inactive Product',
            'slug' => 'inactive-product-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/products');

        $response->assertStatus(200)
                 ->assertJsonFragment(['name' => 'Active Product'])
                 ->assertJsonMissing(['name' => 'Inactive Product']);
    }

    public function test_can_get_all_inactive_products()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Skin Care',
            'slug' => 'skin-care-' . Str::random(5),
        ]);

        Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-ACT-' . Str::random(3),
            'name' => 'Active Product',
            'slug' => 'active-product-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'active',
        ]);

        Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-INA-' . Str::random(3),
            'name' => 'Inactive Product',
            'slug' => 'inactive-product-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/products/inactive');

        $response->assertStatus(200)
                 ->assertJsonFragment(['name' => 'Inactive Product'])
                 ->assertJsonMissing(['name' => 'Active Product']);
    }

    public function test_can_get_single_product_detail()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Body Care',
            'slug' => 'body-care-' . Str::random(5),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-SGL-' . Str::random(3),
            'name' => 'Single Product',
            'slug' => 'single-product-' . Str::random(5),
            'price' => 75000,
            'stock' => 20,
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('data.id', $product->id)
                 ->assertJsonPath('data.name', $product->name);
    }

    public function test_returns_404_if_product_not_found()
    {
        $response = $this->getJson("/api/products/99999");

        $response->assertStatus(404)
                 ->assertJsonPath('status', 'error')
                 ->assertJsonPath('message', 'Produk tidak ditemukan.');
    }

    public function test_admin_can_store_new_product()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'New Category',
            'slug' => 'new-category-' . Str::random(5),
        ]);

        $payload = [
            'category_id' => $category->id,
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product Name',
            'description' => 'This is a test description',
            'price' => 150000,
            'discount_price' => 120000,
            'stock' => 50,
            'status' => 'active',
            'color' => [
                ['hex' => '#000000', 'name' => 'Black']
            ]
        ];

        $response = $this->authenticateAdmin()->postJson('/api/products', $payload);

        // PERBAIKAN 1: Menyesuaikan assert dengan cast 'decimal:2' dari Model
        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'sku' => 'TEST-SKU-001',
                     'name' => 'Test Product Name',
                     'price' => "150000.00",
                     'discount_price' => "120000.00",
                 ]);

        $this->assertDatabaseHas('products', ['sku' => 'TEST-SKU-001']);
        $this->assertDatabaseHas('product_stocks', ['quantity' => 50]);
    }

    public function test_store_validates_required_fields()
    {
        $response = $this->authenticateAdmin()->postJson('/api/products', []);

        // PERBAIKAN 2: Menyesuaikan karena ProductController mereturn validasi manual (return response()->json($validator->errors(), 422))
        $response->assertStatus(422)
                 ->assertJsonStructure(['category_id', 'sku', 'name', 'price', 'stock', 'status']);
    }

    public function test_admin_can_update_product()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Update Category',
            'slug' => 'update-category-' . Str::random(5),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'OLD-SKU-' . Str::random(3),
            'name' => 'Old Name',
            'slug' => 'old-name-' . Str::random(5),
            'price' => 100000,
            'stock' => 10,
            'status' => 'active',
        ]);

        $payload = [
            'category_id' => $category->id,
            'sku' => $product->sku,
            'name' => 'New Awesome Name',
            'price' => 120000,
            'discount_price' => 99000,
            'status' => 'active'
        ];

        $response = $this->authenticateAdmin()->putJson("/api/products/{$product->id}", $payload);

        // PERBAIKAN 3: Menyesuaikan assert dengan cast 'decimal:2'
        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'New Awesome Name',
                     'price' => "120000.00",
                     'discount_price' => "99000.00",
                 ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'New Awesome Name',
        ]);
    }

    public function test_admin_can_deactivate_product()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Del Category',
            'slug' => 'del-category-' . Str::random(5),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'DEL-SKU-' . Str::random(3),
            'name' => 'To Be Deleted',
            'slug' => 'to-be-deleted-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'active'
        ]);

        $response = $this->authenticateAdmin()->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Product deactivated');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'inactive'
        ]);
    }

    public function test_admin_can_restore_product()
    {
        $category = Category::create([
            'code' => 'CAT-' . Str::random(4),
            'name' => 'Restore Category',
            'slug' => 'res-category-' . Str::random(5),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'RES-SKU-' . Str::random(3),
            'name' => 'To Be Restored',
            'slug' => 'to-be-restored-' . Str::random(5),
            'price' => 50000,
            'stock' => 10,
            'status' => 'inactive'
        ]);

        // PERBAIKAN 4: Ubah method menjadi PUT, asumsi rute yang didaftarkan di api.php adalah PUT atau PATCH
        $response = $this->authenticateAdmin()->putJson("/api/products/{$product->id}/restore");

        // Note: Jika test ini MASIH gagal (404 atau 405), pastikan route untuk restore di routes/api.php
        // menggunakan method PUT: Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Product activated');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'active'
        ]);
    }
}
