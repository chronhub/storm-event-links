<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Stream\StreamName;

/**
 * The checkpointed read for a projection that folds a derived stream, a DerivedStreamProjection:
 * inner-joins `event_links` on `source_sequence`, narrows to the target stream, and resumes on the
 * global `sequence_no`, `>` checkpoint and `<=` safe head, oldest first.
 *
 * `… JOIN event_links l ON l.source_sequence = e.sequence_no
 *  WHERE l.target_stream = :sourceStream AND e.sequence_no > :afterPosition AND e.sequence_no <= :maxPosition
 *  ORDER BY e.sequence_no ASC LIMIT :limit`.
 *
 * Distinct from DerivedStreamFilter, the ad-hoc browse ordered by `target_position` with no bounds:
 * this is the fold-resumable read. A single-producer derived stream is monotone with `sequence_no`,
 * so ordering and resuming on `sequence_no` is the derived stream's order, and the projector's
 * `sequence_no` checkpoint, safe-head watermark, and `scanMax` apply unchanged.
 *
 * @see DerivedStreamFilter
 */
final readonly class DerivedStreamProjectionFilter implements QueryFilter
{
    /**
     * @param  positive-int  $limit  refused below one: a zero would reach the store as `LIMIT 0`, an
     *                               empty page indistinguishable from a drained window
     *
     * @throws InvalidArgumentException when the limit is not positive
     */
    public function __construct(// @phpstan-ignore throws.unusedType (thrown for the PHPDoc-blind caller the analyzer cannot see)
        public int $afterPosition,
        public int $maxPosition,
        public int $limit,
        public StreamName $sourceStream,
    ) {
        // @phpstan-ignore smaller.alwaysFalse (the PHPDoc positive-int is no runtime guarantee; the gate exists for the callers the analyzer cannot see)
        if ($this->limit < 1) {
            throw new InvalidArgumentException(sprintf('A derived stream projection window limit must be a positive integer, got %d.', $this->limit));
        }
    }

    public function apply(QueryBuilder $qb): void
    {
        $qb->innerJoin('e', 'event_links', 'l', 'l.source_sequence = e.sequence_no')
            ->andWhere('l.target_stream = :sourceStream')
            ->andWhere('e.sequence_no > :afterPosition')
            ->andWhere('e.sequence_no <= :maxPosition')
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('sourceStream', $this->sourceStream->toString(), ParameterType::STRING)
            ->setParameter('afterPosition', $this->afterPosition, ParameterType::INTEGER)
            ->setParameter('maxPosition', $this->maxPosition, ParameterType::INTEGER);
    }
}
