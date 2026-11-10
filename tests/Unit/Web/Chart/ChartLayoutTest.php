<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Chart;

use App\Web\Chart\ChartLink;
use App\Web\Chart\ChartNode;
use App\Web\Chart\ChordLayout;
use App\Web\Chart\DonutLayout;
use App\Web\Chart\Polar;
use App\Web\Chart\SankeyLayout;
use PHPUnit\Framework\TestCase;

final class ChartLayoutTest extends TestCase
{
    public function testPolarConventions(): void
    {
        self::assertSame('0,-10', Polar::point(0.0, 10.0));
        self::assertSame('10,0', Polar::point(M_PI / 2, 10.0));
        self::assertSame('1.5', Polar::number(1.499999));
        self::assertSame('0', Polar::number(-0.001));
        self::assertStringContainsString('A10,10 0 1 1', Polar::arcTo(0.0, 4.0, 10.0));
    }

    public function testChordSizesArcsByLinksAndDropsEmptyNodes(): void
    {
        $chart = (new ChordLayout(100.0))->layout(
            [
                new ChartNode('o:shared', 'Общие', 'var(--owner-0)'),
                new ChartNode('o:p1', 'Анна', 'var(--owner-1)'),
                new ChartNode('c:1', 'Еда', 'var(--cat-0)'),
                new ChartNode('c:2', 'Такси', 'var(--cat-1)'),
            ],
            [
                new ChartLink('o:shared', 'c:1', 300),
                new ChartLink('o:p1', 'c:1', 100),
                new ChartLink('o:p1', 'c:2', 0),
                new ChartLink('o:p1', 'c:2', -50),
            ],
        );

        $keys = array_map(static fn ($group): string => $group->key, $chart->groups);
        self::assertSame(['o:shared', 'o:p1', 'c:1'], $keys);
        self::assertSame([300, 100, 400], array_map(static fn ($group): int => $group->value, $chart->groups));
        self::assertCount(2, $chart->ribbons);
        self::assertSame('var(--owner-0)', $chart->ribbons[0]->color);
        self::assertStringStartsWith('M', $chart->ribbons[0]->path);
        self::assertStringContainsString('Q0,0', $chart->ribbons[0]->path);
        self::assertFalse($chart->isEmpty());
        self::assertTrue((new ChordLayout())->layout([new ChartNode('a', 'A', 'red')], [])->isEmpty());
    }

    public function testSankeyKeepsFlowConservation(): void
    {
        $chart = (new SankeyLayout(400, 200))->layout(
            [
                [new ChartNode('p:1', 'Анна', 'gray'), new ChartNode('p:2', 'Борис', 'gray')],
                [new ChartNode('o:shared', 'Общие', 'teal')],
                [new ChartNode('c:1', 'Еда', 'blue'), new ChartNode('c:9', 'Пусто', 'red')],
            ],
            [
                new ChartLink('p:1', 'o:shared', 150, 'teal'),
                new ChartLink('p:2', 'o:shared', 50, 'teal'),
                new ChartLink('o:shared', 'c:1', 200),
            ],
        );

        $nodes = [];

        foreach ($chart->nodes as $node) {
            $nodes[$node->key] = $node;
        }

        self::assertArrayNotHasKey('c:9', $nodes);
        self::assertSame(200, $nodes['o:shared']->value);
        self::assertSame('186', $nodes['o:shared']->height);
        self::assertSame('7', $nodes['o:shared']->y);
        self::assertSame('end', $nodes['c:1']->anchor);
        self::assertSame('start', $nodes['p:1']->anchor);
        self::assertCount(3, $chart->flows);
        $out = array_values(array_filter($chart->flows, static fn ($flow): bool => $flow->source === 'o:shared'))[0];
        self::assertSame('teal', $out->color);
        self::assertSame('M205,100 C297.5,100 297.5,100 390,100', $out->path);
        self::assertSame('186', $out->width);
        self::assertTrue((new SankeyLayout())->layout([[]], [])->isEmpty());
    }

    public function testDonutPercentagesAndSingleSegment(): void
    {
        $node     = static fn (string $key): ChartNode => new ChartNode($key, $key, 'red');
        $segments = (new DonutLayout())->layout([[$node('a'), 750], [$node('b'), 250], [$node('c'), 0]]);

        self::assertSame([75.0, 25.0], array_map(static fn ($segment): float => $segment->percent, $segments));
        self::assertCount(1, (new DonutLayout())->layout([[$node('a'), 5]]));
        self::assertSame([], (new DonutLayout())->layout([]));
    }
}
