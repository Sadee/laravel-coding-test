<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        // Eager-load relations in one batch query each (4 queries per page in total:
        // count, orders, customers, items) instead of 2 lazy loads per order.
        // Select lists keep the keys needed to match relations (customer_id, order_id).
        $orders = Order::query()
            ->select(['id', 'customer_id', 'status', 'total'])
            ->with([
                'customer:id,name,email',
                'items:id,order_id,product_name,sku,quantity,unit_price',
            ])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return OrderResource::collection($orders);
    }
}
