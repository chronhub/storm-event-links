<?php

declare(strict_types=1);

namespace Storm\EventLinks\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\EventLinks\DerivedStreamProjectionFilter;
use Storm\Stream\StreamName;

/**
 * Unit pin of the construction defaults; the read itself, its join, link order, and limit applied, is
 * covered by the integration test.
 */
final class DerivedStreamFilterTest extends TestCase
{
    #[Test]
    public function it_defaults_the_limit_to_the_declared_constant(): void
    {
        $filter = new DerivedStreamFilter(new StreamName('large_withdrawals'));

        $this->assertSame('large_withdrawals', $filter->targetStream->toString());
        $this->assertSame(DerivedStreamFilter::DEFAULT_LIMIT, $filter->limit);
        $this->assertSame(1000, DerivedStreamFilter::DEFAULT_LIMIT);
    }

    #[Test]
    public function the_smallest_positive_limit_constructs_on_both_filters(): void
    {
        // the refusal floor is exact: one, the smallest legal page, passes on both siblings
        $this->assertSame(1, new DerivedStreamFilter(new StreamName('large_withdrawals'), 1)->limit);
        $this->assertSame(1, new DerivedStreamProjectionFilter(0, 10, 1, new StreamName('large_withdrawals'))->limit);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_browse_filter_refuses_the_zero_limit_its_contract_excludes(): void
    {
        // the constructor enforces its positive-int prose: a zero limit would reach the store as
        // LIMIT 0, an empty page indistinguishable from an actually empty derived stream
        $this->expectException(InvalidArgumentException::class);

        new DerivedStreamFilter(new StreamName('large_withdrawals'), 0); // @phpstan-ignore argument.type (deliberate: the zero the documented positive-int excludes)
    }

    #[Test]
    #[Group('adversarial')]
    public function the_projection_filter_refuses_the_zero_limit_its_contract_excludes(): void
    {
        // the checkpointed sibling holds the same floor: a zero limit would make a non-empty window
        // look drained and stop a caller without advancing its checkpoint
        $this->expectException(InvalidArgumentException::class);

        new DerivedStreamProjectionFilter(0, 10, 0, new StreamName('large_withdrawals')); // @phpstan-ignore argument.type (deliberate: the zero the documented positive-int excludes)
    }
}
