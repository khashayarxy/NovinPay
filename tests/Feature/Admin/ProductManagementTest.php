<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'کارتخوان نورین مدل X',
            'slug' => 'novin-pos-x',
            'summary' => 'خلاصهٔ معرفی محصول',
            'description' => 'توضیحات کامل محصول.',
            'category' => 'pos_terminal',
            'condition' => 'new',
            'price' => 1500000,
            'stock_status' => 'in_stock',
            'is_published' => '1',
            'spec_labels' => ['نوع اتصال', ''],
            'spec_values' => ['شبکه', ''],
        ], $overrides);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'محصول موجود',
            'slug' => 'existing-product-'.uniqid(),
            'category' => 'pos_terminal',
            'condition' => 'new',
            'stock_status' => 'in_stock',
            'is_published' => true,
        ], $overrides));
    }

    public function test_index_page_lists_products_including_unpublished_ones(): void
    {
        $this->createProduct(['name' => 'محصول منتشرشده', 'slug' => 'published-1', 'is_published' => true]);
        $this->createProduct(['name' => 'محصول پیش‌نویس', 'slug' => 'draft-1', 'is_published' => false]);

        $response = $this->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertSee('محصول منتشرشده');
        $response->assertSee('محصول پیش‌نویس');
        $response->assertSee('منتشرشده');
        $response->assertSee('پیش‌نویس');
    }

    public function test_index_escapes_product_names_in_delete_confirmation(): void
    {
        $this->createProduct([
            'name' => "x');alert(document.domain);//\" onload=\"alert(1)",
            'slug' => 'xss-guard-product',
        ]);

        $response = $this->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertDontSee('onsubmit=', false);
        $response->assertDontSee("');alert(document.domain)", false);
        $response->assertSee('data-confirm=', false);
        $response->assertSee('&#039;);alert(document.domain)', false);
    }

    public function test_create_page_renders(): void
    {
        $this->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('افزودن محصول')
            ->assertSee('مشخصات فنی');
    }

    public function test_spec_row_inputs_have_programmatic_label_associations(): void
    {
        $response = $this->get(route('admin.products.create'));

        $response->assertOk();
        $response->assertSee('for="spec-label-0"', false);
        $response->assertSee('id="spec-label-0"', false);
        $response->assertSee('for="spec-value-0"', false);
        $response->assertSee('id="spec-value-0"', false);
        $response->assertSee('for="spec-label-2"', false);
        $response->assertSee('id="spec-value-2"', false);
        $response->assertSee('data-spec-next-index="3"', false);
        $response->assertSee('for="spec-label-__INDEX__"', false);
        $response->assertSee('id="spec-value-__INDEX__"', false);
    }

    public function test_edit_page_numbers_spec_rows_after_existing_values(): void
    {
        $product = $this->createProduct([
            'specifications' => ['وزن' => '۱ کیلوگرم', 'رنگ' => 'مشکی'],
        ]);

        $response = $this->get(route('admin.products.edit', $product));

        $response->assertOk();
        $response->assertSee('for="spec-label-0"', false);
        $response->assertSee('for="spec-label-1"', false);
        $response->assertSee('for="spec-label-2"', false);
        $response->assertSee('for="spec-value-4"', false);
        $response->assertSee('data-spec-next-index="5"', false);
    }

    public function test_store_creates_product_with_specifications(): void
    {
        $response = $this->post(route('admin.products.store'), $this->validPayload());

        $response->assertRedirect(route('admin.products.index'));
        $response->assertSessionHas('success');

        $product = Product::query()->where('slug', 'novin-pos-x')->firstOrFail();

        $this->assertSame('کارتخوان نورین مدل X', $product->name);
        $this->assertSame('pos_terminal', $product->category->value);
        $this->assertSame(1500000, $product->price);
        $this->assertTrue($product->is_published);
        $this->assertFalse($product->is_sample);
        $this->assertSame(['نوع اتصال' => 'شبکه'], $product->specifications);
    }

    public function test_store_generates_slug_from_ascii_name_when_slug_is_empty(): void
    {
        $this->post(route('admin.products.store'), $this->validPayload([
            'name' => 'Novin Thermal Printer',
            'slug' => '',
        ]));

        $this->assertDatabaseHas('products', ['slug' => 'novin-thermal-printer']);
    }

    public function test_store_generates_fallback_slug_for_persian_name(): void
    {
        $this->post(route('admin.products.store'), $this->validPayload([
            'name' => 'دستگاه کارتخوان جدید',
            'slug' => '',
        ]));

        $slug = Product::query()->latest('id')->first()->slug;

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    public function test_store_validates_required_fields_with_persian_messages(): void
    {
        $response = $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), []);

        $response->assertRedirect(route('admin.products.create'));
        $response->assertSessionHasErrors(['name', 'category', 'condition', 'stock_status']);

        $this->assertStringContainsString('الزامی است', session('errors')->first('name'));
        $this->assertStringContainsString('الزامی است', session('errors')->first('category'));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_store_rejects_invalid_enum_values(): void
    {
        $response = $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload([
                'category' => 'unknown-category',
                'condition' => 'broken',
                'stock_status' => 'maybe',
            ]));

        $response->assertSessionHasErrors(['category', 'condition', 'stock_status']);
        $this->assertStringContainsString('نامعتبر است', session('errors')->first('category'));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_store_rejects_duplicate_slug_with_persian_message(): void
    {
        $this->createProduct(['slug' => 'taken-slug']);

        $response = $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload(['slug' => 'taken-slug']));

        $response->assertSessionHasErrors('slug');
        $this->assertStringContainsString('قبلاً برای محصول دیگری استفاده شده', session('errors')->first('slug'));
    }

    public function test_store_rejects_malformed_slug(): void
    {
        $response = $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload(['slug' => 'Not A Slug!']));

        $response->assertSessionHasErrors('slug');
        $this->assertStringContainsString('اسلاگ باید فقط شامل', session('errors')->first('slug'));
    }

    public function test_store_rejects_negative_and_non_numeric_price(): void
    {
        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload(['price' => -5]))
            ->assertSessionHasErrors('price');

        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload(['price' => 'abc']))
            ->assertSessionHasErrors('price');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_store_accepts_persian_digits_in_price(): void
    {
        $this->post(route('admin.products.store'), $this->validPayload(['price' => '۱۵۰۰۰۰۰']));

        $this->assertDatabaseHas('products', ['slug' => 'novin-pos-x', 'price' => 1500000]);
    }

    public function test_store_saves_uploaded_image_on_the_public_disk(): void
    {
        Storage::fake('public');

        $this->post(route('admin.products.store'), $this->validPayload([
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ]));

        $product = Product::query()->where('slug', 'novin-pos-x')->firstOrFail();

        $this->assertNotNull($product->image_path);
        $this->assertTrue(str_starts_with($product->image_path, 'products/'));
        $this->assertMatchesRegularExpression('#^products/[A-Za-z0-9._-]+\.jpg$#', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_store_rejects_non_image_files(): void
    {
        Storage::fake('public');

        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload([
                'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Product::query()->count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_store_rejects_oversized_images(): void
    {
        Storage::fake('public');

        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload([
                'image' => UploadedFile::fake()->image('big.jpg')->size(3000),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertStringContainsString('۲ مگابایت', session('errors')->first('image'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_edit_page_prefills_existing_values(): void
    {
        $product = $this->createProduct([
            'name' => 'محصول برای ویرایش',
            'summary' => 'خلاصهٔ قبلی',
            'is_published' => false,
        ]);

        $response = $this->get(route('admin.products.edit', $product));

        $response->assertOk();
        $response->assertSee('محصول برای ویرایش');
        $response->assertSee('خلاصهٔ قبلی');
        $response->assertSee('ویرایش محصول');
    }

    public function test_update_edits_product_fields(): void
    {
        $product = $this->createProduct(['is_published' => true]);

        $response = $this->put(route('admin.products.update', $product), $this->validPayload([
            'name' => 'نام تازه',
            'price' => 2000000,
            'is_sample' => '1',
        ]));

        $response->assertRedirect(route('admin.products.index'));
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('نام تازه', $product->name);
        $this->assertSame(2000000, $product->price);
        $this->assertTrue($product->is_sample);
    }

    public function test_update_can_unpublish_a_product(): void
    {
        $product = $this->createProduct(['is_published' => true]);

        $payload = $this->validPayload();
        unset($payload['is_published']);

        $this->put(route('admin.products.update', $product), $payload);

        $this->assertFalse($product->refresh()->is_published);
    }

    public function test_update_keeps_existing_image_when_no_new_file_is_uploaded(): void
    {
        Storage::fake('public');

        $oldPath = 'products/existing-image.jpg';
        Storage::disk('public')->put($oldPath, 'fake-image-data');

        $product = $this->createProduct(['image_path' => $oldPath]);

        $payload = $this->validPayload();
        unset($payload['image']);

        $this->put(route('admin.products.update', $product), $payload);

        $this->assertSame($oldPath, $product->refresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_update_replaces_image_and_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $this->post(route('admin.products.store'), $this->validPayload([
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]));

        $product = Product::query()->where('slug', 'novin-pos-x')->firstOrFail();
        $oldPath = $product->image_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->put(route('admin.products.update', $product), $this->validPayload([
            'image' => UploadedFile::fake()->image('second.jpg'),
        ]));

        $product->refresh();
        $this->assertNotSame($oldPath, $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_destroy_removes_product_and_its_image(): void
    {
        Storage::fake('public');

        $this->post(route('admin.products.store'), $this->validPayload([
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ]));

        $product = Product::query()->where('slug', 'novin-pos-x')->firstOrFail();
        Storage::disk('public')->assertExists($product->image_path);

        $response = $this->delete(route('admin.products.destroy', $product));

        $response->assertRedirect(route('admin.products.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('products', ['slug' => 'novin-pos-x']);
        Storage::disk('public')->assertMissing($product->image_path);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_destroy_works_for_products_without_images(): void
    {
        $product = $this->createProduct();

        $this->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->getKey()]);
    }

    public function test_update_validates_input_like_store(): void
    {
        $product = $this->createProduct(['slug' => 'first-product']);

        $this->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), $this->validPayload([
                'slug' => 'bad slug!',
                'category' => 'nope',
            ]))
            ->assertSessionHasErrors(['slug', 'category']);

        $this->assertSame('first-product', $product->refresh()->slug);
    }

    public function test_unauthenticated_visitor_cannot_see_product_data_in_admin(): void
    {
        $product = $this->createProduct(['name' => 'اطلاعات محرمانهٔ مدیریت']);

        auth()->logout();

        $this->get(route('admin.products.index'))
            ->assertRedirect(route('admin.login'))
            ->assertDontSee('اطلاعات محرمانهٔ مدیریت');
    }
}
