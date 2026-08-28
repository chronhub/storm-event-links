<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Storm\Stream\StreamName;

/**
 * The head of a derived link stream in `sequence_no` space: the highest `source_sequence` its
 * producer has already linked into the target.
 *
 * A derived consumer folds `event_links` joined on `source_sequence`, but bounds its scan by the
 * event-store safe head, which advances over source positions the producer has NOT yet linked. Left
 * to that frontier, a consumer scheduled ahead of its producer checkpoints past those positions and
 * every late link falls permanently below its checkpoint. This head is the correct frontier instead:
 * the consumer must never advance past the last position its producer actually linked. Behind it may
 * be legitimately un-linked positions, folded as a tail; ahead of it is undecided, so wait, never skip.
 *
 * @see DerivedStreamProjectionFilter
 * @see \Storm\Projector\Run\Stage\ReadBatch
 */
interface DerivedStreamHead
{
    /**
     * The highest `source_sequence` linked into `$target`, or 0 when the target has no links yet.
     */
    public function headFor(StreamName $target): int;
}
