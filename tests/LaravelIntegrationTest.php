<?php

declare(strict_types=1);

namespace Tests;

require_once __DIR__.'/helpers.php';

use Henderkes\ParallelFork\Runtime;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

class LaravelIntegrationTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = \tempnam(\sys_get_temp_dir(), 'parallel_laravel_');

        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $this->dbPath,
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('orders', function ($table) {
            $table->increments('id');
            $table->string('customer');
            $table->string('product');
            $table->integer('quantity');
            $table->float('unit_price');
        });

        $orders = [
            ['Alice', 'Widget', 10, 5.50],
            ['Alice', 'Gadget', 2, 45.00],
            ['Bob', 'Widget', 5, 5.50],
            ['Bob', 'Shirt', 3, 29.99],
            ['Bob', 'Hat', 1, 15.00],
            ['Carol', 'Drill', 1, 89.99],
            ['Carol', 'Hammer', 4, 22.00],
            ['Carol', 'Widget', 8, 5.50],
            ['Dave', 'Book', 6, 9.99],
            ['Dave', 'Pants', 2, 49.99],
        ];
        foreach ($orders as [$customer, $product, $qty, $price]) {
            Capsule::table('orders')->insert([
                'customer' => $customer,
                'product' => $product,
                'quantity' => $qty,
                'unit_price' => $price,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->dbPath) && \file_exists($this->dbPath)) {
            @\unlink($this->dbPath);
        }
    }

    public function test_parallel_customer_invoices(): void
    {
        $customers = Capsule::table('orders')->distinct()->pluck('customer')->toArray();

        // Fork one child per customer — each computes that customer's invoice
        $rt = new Runtime;
        $futures = [];
        foreach ($customers as $customer) {
            $futures[$customer] = $rt->run(function () use ($customer) {
                $orders = Capsule::table('orders')
                    ->where('customer', $customer)
                    ->get();

                $total = 0.0;
                $lines = [];
                foreach ($orders as $order) {
                    $lineTotal = $order->quantity * $order->unit_price;
                    $total += $lineTotal;
                    $lines[] = "{$order->quantity}x {$order->product} = {$lineTotal}";
                }

                return [
                    'customer' => $customer,
                    'lines' => $lines,
                    'total' => \round($total, 2),
                ];
            });
        }

        // Parent collects all invoices and sums revenue
        $revenue = 0.0;
        $invoices = [];
        foreach ($futures as $customer => $future) {
            $invoice = $future->value();
            $this->assertSame($customer, $invoice['customer']);
            $this->assertGreaterThan(0, $invoice['total']);
            $this->assertNotEmpty($invoice['lines']);
            $revenue += $invoice['total'];
            $invoices[$customer] = $invoice;
        }

        // Alice: 10*5.50 + 2*45 = 145, Bob: 5*5.50 + 3*29.99 + 15 = 132.47
        // Carol: 89.99 + 4*22 + 8*5.50 = 221.99, Dave: 6*9.99 + 2*49.99 = 159.92
        $this->assertEqualsWithDelta(659.38, $revenue, 0.01);
        $this->assertCount(4, $invoices);
        $rt->close();
    }

    public function test_parallel_bulk_insert_then_aggregate(): void
    {
        $rt = new Runtime;

        // 4 children each insert a batch of orders
        $batches = [
            ['Eve', 'Laptop', 1, 999.99],
            ['Frank', 'Monitor', 2, 349.00],
            ['Grace', 'Keyboard', 10, 79.99],
            ['Hank', 'Mouse', 20, 25.00],
        ];
        $insertFutures = [];
        foreach ($batches as [$customer, $product, $qty, $price]) {
            $insertFutures[] = $rt->run(function () use ($customer, $product, $qty, $price) {
                Capsule::table('orders')->insert([
                    'customer' => $customer,
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $price,
                ]);

                return true;
            });
        }

        // Wait for all inserts
        foreach ($insertFutures as $f) {
            $this->assertTrue($f->value());
        }

        // Now aggregate in parallel: top spenders and product revenue
        $spenderFuture = $rt->run(function () {
            return Capsule::table('orders')
                ->selectRaw('customer, SUM(quantity * unit_price) as total')
                ->groupBy('customer')
                ->orderByDesc('total')
                ->limit(3)
                ->get()
                ->map(fn ($r) => ['customer' => $r->customer, 'total' => \round($r->total, 2)])
                ->toArray();
        });

        $productFuture = $rt->run(function () {
            return Capsule::table('orders')
                ->selectRaw('product, SUM(quantity) as total_qty')
                ->groupBy('product')
                ->orderByDesc('total_qty')
                ->limit(3)
                ->get()
                ->map(fn ($r) => ['product' => $r->product, 'qty' => $r->total_qty])
                ->toArray();
        });

        $topSpenders = $spenderFuture->value();
        $topProducts = $productFuture->value();

        $this->assertCount(3, $topSpenders);
        $this->assertCount(3, $topProducts);
        $this->assertGreaterThan(0, $topSpenders[0]['total']);

        // Parent connection still works
        $total = Capsule::table('orders')->count();
        $this->assertEquals(14, $total); // 10 original + 4 new
        $rt->close();
    }

    public function test_parallel_price_simulation(): void
    {
        // Parent reads all orders
        $orders = Capsule::table('orders')->get()->toArray();
        $chunks = \array_chunk($orders, 3);

        // Each child applies discount + tax to its chunk
        $rt = new Runtime;
        $futures = [];
        foreach ($chunks as $chunk) {
            $futures[] = $rt->run(function () use ($chunk) {
                $result = 0.0;
                foreach ($chunk as $order) {
                    $subtotal = $order->quantity * $order->unit_price;
                    $discounted = $subtotal * 0.90; // 10% discount
                    $taxed = $discounted * 1.21;    // 21% VAT
                    $result += \round($taxed, 2);
                }

                return $result;
            });
        }

        $totalWithTax = 0.0;
        foreach ($futures as $f) {
            $totalWithTax += $f->value();
        }

        // 659.38 * 0.90 * 1.21 ≈ 718.02
        $this->assertGreaterThan(600.0, $totalWithTax);
        $this->assertLessThan(800.0, $totalWithTax);
        $rt->close();
    }
}
