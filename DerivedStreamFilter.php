<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Stream\StreamName;

/**
 * Reads a derived stream, the links a LinkProjection wrote into `event_links` via EventLinkSchema,
 * back as an ordered event stream: inner-joins `event_links` on `source_sequence = e.sequence_no`,
 * keeps only the target's links, and orders by the dense `target_position`, the link-write order,
 * not the global `sequence_no`.
 *
 * `… JOIN event_links l ON l.source_sequence = e.sequence_no
 *  WHERE l.target_stream = :targetStream ORDER BY l.target_position ASC LIMIT :limit`.
 *
 * - No safe-head watermark: a derived stream is gap-free by construction, since a single-lease
 *   LinkProjection assigns dense `target_position`s in one tx, so, unlike a category read, a
 *   derived-stream read can never see an in-flight gap below the head. What makes the `max + 1`
 *   race-free for two workers of the same projection is the `FOR UPDATE` the acquire stage holds on
 *   the shared checkpoint row, lease or no lease, and `EventLinkWriter` says so at the site: the
 *   stronger guard, not the lease. A writer that assigned positions outside that transaction would
 *   need the watermark this filter deliberately omits.
 *
 * - The yielded EventRecord still carries its global `sequence_no` as its position; `target_position`
 *   is only the ordering key within this derived stream.
 *
 * - A resume cursor reading after a `target_position` is intentionally absent: no current reader needs
 *   it, and a folding consumer's checkpoint semantics, `target_position` versus the global
 *   `sequence_no`, are undecided, to be added when that consumer lands.
 *
 * Turns `event_links` from write-only bookkeeping into a BOUNDED browse for LiveQuery `inspect`:
 * truncation at `limit` is indistinguishable from a complete stream, which a browse tolerates. A
 * folding consumer reads through `DerivedStreamProjectionFilter`, the checkpointed sibling, never
 * this filter, which would silently fold the first `limit` links forever.
 *
 * @see EventRecord
 */
final readonly class DerivedStreamFilter implements QueryFilter
{
    public const int DEFAULT_LIMIT = 1000;

    /**
     * @param  positive-int  $limit  refused below one: a zero would reach the store as `LIMIT 0`, an
     *                               empty page indistinguishable from an empty stream
     *
     * @throws InvalidArgumentException when the limit is not positive
     */
    public function __construct(// @phpstan-ignore throws.unusedType (thrown for the PHPDoc-blind caller the analyzer cannot see)
        public StreamName $targetStream,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        // @phpstan-ignore smaller.alwaysFalse (the PHPDoc positive-int is no runtime guarantee; the gate exists for the callers the analyzer cannot see)
        if ($this->limit < 1) {
            throw new InvalidArgumentException(sprintf('A derived stream browse limit must be a positive integer, got %d.', $this->limit));
        }
    }

    public function apply(QueryBuilder $qb): void
    {
        $qb->innerJoin('e', 'event_links', 'l', 'l.source_sequence = e.sequence_no')
            ->andWhere('l.target_stream = :targetStream')
            ->orderBy('l.target_position', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('targetStream', $this->targetStream->toString(), ParameterType::STRING);
    }
}
