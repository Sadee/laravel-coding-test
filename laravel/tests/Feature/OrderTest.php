<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_endpoint_returns_list(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create();
        OrderItem::factory(3)->for($order)->create();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonCount(3, 'data.0.items');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'status',
                    'total',
                    'customer' => ['id', 'name', 'email'],
                    'items' => [
                        '*' => ['id', 'product_name', 'sku', 'quantity', 'unit_price'],
                    ],
                ],
            ],
            'links',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }

    public function test_orders_endpoint_returns_empty_list_when_no_orders(): void
    {
        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_order_includes_correct_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        Order::factory()->for($customer)->create();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.customer.name', 'Jane Doe');
        $response->assertJsonPath('data.0.customer.email', 'jane@example.com');
    }

    public function test_query_count_does_not_grow_with_number_of_orders(): void
    {
        Customer::factory(5)->create()->each(function (Customer $customer) {
            Order::factory(6)->for($customer)->create()->each(
                fn (Order $order) => OrderItem::factory(3)->for($order)->create()
            );
        });

        DB::enableQueryLog();
        $response = $this->getJson('/api/orders?per_page=30');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $response->assertJsonCount(30, 'data');
        // count + orders + customers + items
        $this->assertCount(4, $queries);
    }

    public function test_orders_are_paginated(): void
    {
        Order::factory(30)->for(Customer::factory())->create();

        $this->getJson('/api/orders')
            ->assertStatus(200)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/orders?per_page=10&page=3')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_per_page_is_clamped(): void
    {
        Order::factory()->for(Customer::factory())->create();

        $this->getJson('/api/orders?per_page=1000')->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/orders?per_page=0')->assertJsonPath('meta.per_page', 1);
    }

    public function test_orders_are_returned_newest_first(): void
    {
        $orders = Order::factory(3)->for(Customer::factory())->create();

        $this->getJson('/api/orders')
            ->assertJsonPath('data.0.id', $orders->last()->id)
            ->assertJsonPath('data.2.id', $orders->first()->id);
    }
}
