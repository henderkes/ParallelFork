<?php

declare(strict_types=1);

namespace Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Henderkes\ParallelFork\Runtime;
use PHPUnit\Framework\TestCase;
use Tests\Entity\Product;

class DoctrineIntegrationTest extends TestCase
{
    private string $dbPath;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->dbPath = \tempnam(\sys_get_temp_dir(), 'parallel_doctrine_').'.sqlite';

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__.'/Entity'], true
        );
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath,
        ]);
        $this->em = new EntityManager($conn, $config);

        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $products = [
            new Product('Widget A', 12.50, 'electronics'),
            new Product('Widget B', 7.25, 'electronics'),
            new Product('Gadget C', 45.00, 'electronics'),
            new Product('Shirt D', 29.99, 'clothing'),
            new Product('Pants E', 49.99, 'clothing'),
            new Product('Hat F', 15.00, 'clothing'),
            new Product('Hammer G', 22.00, 'tools'),
            new Product('Drill H', 89.99, 'tools'),
            new Product('Saw I', 34.50, 'tools'),
            new Product('Wrench J', 11.75, 'tools'),
            new Product('Book K', 9.99, 'books'),
            new Product('Book L', 14.99, 'books'),
        ];
        foreach ($products as $p) {
            $this->em->persist($p);
        }
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            try {
                $this->em->getConnection()->close();
            } catch (\Throwable) {
            }
        }
        if (isset($this->dbPath) && \file_exists($this->dbPath)) {
            @\unlink($this->dbPath);
        }
    }

    public function test_parallel_category_aggregation(): void
    {
        $em = $this->em;
        $categories = $em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT category FROM products'
        );

        // One child per category — each queries via repository and computes stats
        $rt = new Runtime;
        $futures = [];
        foreach ($categories as $cat) {
            $futures[$cat] = $rt->run(function () use ($em, $cat) {
                $products = $em->getRepository(Product::class)->findBy(['category' => $cat]);
                $prices = \array_map(fn (Product $p) => $p->getPrice(), $products);

                return [
                    'category' => $cat,
                    'count' => \count($prices),
                    'sum' => \array_sum($prices),
                    'avg' => \round(\array_sum($prices) / \count($prices), 2),
                    'max' => \max($prices),
                ];
            });
        }

        $grandTotal = 0.0;
        $totalProducts = 0;
        foreach ($futures as $cat => $future) {
            $stats = $future->value();
            $this->assertSame($cat, $stats['category']);
            $this->assertGreaterThan(0, $stats['count']);
            $grandTotal += $stats['sum'];
            $totalProducts += $stats['count'];
        }

        $this->assertEquals(12, $totalProducts);
        $this->assertEqualsWithDelta(342.95, $grandTotal, 0.01);
        $rt->close();
    }

    public function test_parallel_persist_from_children(): void
    {
        $em = $this->em;
        $rt = new Runtime;

        // 4 children each persist products via EntityManager
        $newCategories = ['garden', 'kitchen', 'sports', 'office'];
        $futures = [];
        foreach ($newCategories as $i => $cat) {
            $futures[] = $rt->run(function () use ($em, $cat, $i) {
                for ($j = 0; $j < 3; $j++) {
                    $em->persist(new Product("New-{$cat}-{$j}", 10.0 + $i + $j, $cat));
                }
                $em->flush();

                return \count($em->getRepository(Product::class)->findBy(['category' => $cat]));
            });
        }

        foreach ($futures as $f) {
            $this->assertEquals(3, $f->value());
        }

        // Parent reconnects and verifies: 12 original + 12 new = 24
        $this->em->getConnection()->close();
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $total = $conn->fetchOne('SELECT COUNT(*) FROM products');
        $this->assertEquals(24, $total);
        $conn->close();
        $rt->close();
    }

    public function test_parallel_price_calculation(): void
    {
        $em = $this->em;

        // Parent loads all products via repository
        $allProducts = $em->getRepository(Product::class)->findAll();
        $chunks = \array_chunk($allProducts, 4);

        // Children compute discounted+taxed totals per chunk
        $rt = new Runtime;
        $futures = [];
        foreach ($chunks as $chunk) {
            $futures[] = $rt->run(function () use ($chunk) {
                $total = 0.0;
                foreach ($chunk as $product) {
                    $discounted = $product->getPrice() * 0.85;
                    $taxed = $discounted * 1.21;
                    $total += \round($taxed, 2);
                }

                return $total;
            });
        }

        $grandTotal = 0.0;
        foreach ($futures as $f) {
            $grandTotal += $f->value();
        }

        // 342.95 * 0.85 * 1.21 ≈ 352.67
        $this->assertGreaterThan(300.0, $grandTotal);
        $this->assertLessThan(400.0, $grandTotal);
        $rt->close();
    }

    public function test_parent_entity_manager_survives_forks(): void
    {
        $em = $this->em;
        $rt = new Runtime;

        // Warm up parent connection
        $em->getRepository(Product::class)->findAll();

        // Fork 5 children that each use the captured EM
        $futures = [];
        for ($i = 0; $i < 5; $i++) {
            $futures[] = $rt->run(function () use ($em, $i) {
                $all = $em->getRepository(Product::class)->findAll();
                $em->persist(new Product("fork-$i", 1.00, 'test'));
                $em->flush();

                return \count($all);
            });
        }

        foreach ($futures as $f) {
            $this->assertGreaterThanOrEqual(12, $f->value());
        }

        // Parent's EM still works
        $electronics = $em->getRepository(Product::class)->findBy(['category' => 'electronics']);
        $this->assertCount(3, $electronics);
        $this->assertSame('Widget A', $electronics[0]->getName());
        $rt->close();
    }

    public function test_parallel_queries_with_captured_repo(): void
    {
        $repo = $this->em->getRepository(Product::class);
        $rt = new Runtime;

        // Each child gets only the repo — no EM needed for read-only work
        $categories = ['electronics', 'clothing', 'tools', 'books'];
        $futures = [];
        foreach ($categories as $cat) {
            $futures[$cat] = $rt->run(function () use ($repo, $cat) {
                $products = $repo->findBy(['category' => $cat]);
                $cheapest = null;
                $mostExpensive = null;
                foreach ($products as $p) {
                    if ($cheapest === null || $p->getPrice() < $cheapest->getPrice()) {
                        $cheapest = $p;
                    }
                    if ($mostExpensive === null || $p->getPrice() > $mostExpensive->getPrice()) {
                        $mostExpensive = $p;
                    }
                }

                return [
                    'count' => \count($products),
                    'cheapest' => $cheapest->getName(),
                    'most_expensive' => $mostExpensive->getName(),
                    'spread' => \round($mostExpensive->getPrice() - $cheapest->getPrice(), 2),
                ];
            });
        }

        $results = [];
        foreach ($futures as $cat => $f) {
            $results[$cat] = $f->value();
        }

        $this->assertEquals(3, $results['electronics']['count']);
        $this->assertSame('Widget B', $results['electronics']['cheapest']);
        $this->assertSame('Gadget C', $results['electronics']['most_expensive']);
        $this->assertEqualsWithDelta(37.75, $results['electronics']['spread'], 0.01);

        $this->assertEquals(4, $results['tools']['count']);
        $this->assertEquals(2, $results['books']['count']);

        // Parent repo still works
        $this->assertCount(12, $repo->findAll());
        $rt->close();
    }

    public function test_return_entities_from_child(): void
    {
        $repo = $this->em->getRepository(Product::class);
        $rt = new Runtime;

        // Children query and return actual entity objects to the parent
        $futures = [
            'electronics' => $rt->run(function () use ($repo) {
                return $repo->findBy(['category' => 'electronics']);
            }),
            'tools' => $rt->run(function () use ($repo) {
                return $repo->findBy(['category' => 'tools']);
            }),
        ];

        $electronics = $futures['electronics']->value();
        $tools = $futures['tools']->value();

        // Entities come back as proper objects with all data intact
        $this->assertCount(3, $electronics);
        $this->assertCount(4, $tools);
        $this->assertInstanceOf(Product::class, $electronics[0]);
        $this->assertInstanceOf(Product::class, $tools[0]);

        // Properties are accessible
        $names = \array_map(fn (Product $p) => $p->getName(), $electronics);
        $this->assertContains('Widget A', $names);
        $this->assertContains('Gadget C', $names);

        // Can do further computation on the returned entities
        $toolPrices = \array_map(fn (Product $p) => $p->getPrice(), $tools);
        $this->assertEqualsWithDelta(158.24, \array_sum($toolPrices), 0.01);

        $rt->close();
    }

    public function test_arrow_function_with_repo(): void
    {
        $repo = $this->em->getRepository(Product::class);
        $rt = new Runtime;

        $futures = [
            'electronics' => $rt->run(fn () => \count($repo->findBy(['category' => 'electronics']))),
            'tools' => $rt->run(fn () => \count($repo->findBy(['category' => 'tools']))),
            'clothing' => $rt->run(fn () => \count($repo->findBy(['category' => 'clothing']))),
            'books' => $rt->run(fn () => \count($repo->findBy(['category' => 'books']))),
        ];

        $this->assertSame(3, $futures['electronics']->value());
        $this->assertSame(4, $futures['tools']->value());
        $this->assertSame(3, $futures['clothing']->value());
        $this->assertSame(2, $futures['books']->value());

        // Parent repo still works
        $this->assertCount(12, $repo->findAll());
        $rt->close();
    }

    public function test_arrow_function_with_em_and_repo(): void
    {
        $em = $this->em;
        $repo = $em->getRepository(Product::class);
        $rt = new Runtime;

        // Arrow function captures both $em and $repo — reconnection must handle both
        $future = $rt->run(fn () => array_map(
            fn (Product $p) => ['name' => $p->getName(), 'price' => $p->getPrice()],
            $repo->findBy(['category' => 'electronics'])
        ));

        $result = $future->value();
        $this->assertCount(3, $result);
        $this->assertSame('Widget A', $result[0]['name']);

        // Parent still works
        $this->assertSame(12, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM products'));
        $rt->close();
    }
}
