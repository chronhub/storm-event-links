<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Storm\Stream\StreamName;

/**
 * The membership revision of a derived link stream: how many times its links have been destructively
 * rebuilt. Bumped by `EventLinkWriter` in the statement that deletes them; 0 for a stream that has never
 * been rebuilt, which is also what an absent row reads as.
 *
 * A consumer resumes on the global `source_sequence`, so its checkpoint asserts "everything linked up to
 * here is folded". That assertion is about a SET, not a position, and a rebuild can change the set below
 * the checkpoint without moving it: a producer whose selection widens is refused by the topology gate and
 * routed through bump + reset, whose replay may link a source position lower than what the consumer
 * already passed. Nothing in the stream's own shape reveals that: `max(source_sequence)` is unchanged or
 * higher, and target positions are dense either way. The revision is the missing identity, stamped onto
 * the consumer's row while its checkpoint is 0 and compared on every later run.
 *
 * Deliberately a separate port from DerivedStreamHead, not a second method on it: the head is a FRONTIER
 * that moves with every link and is read per batch, the revision is an IDENTITY that changes only on a
 * rebuild and is read once per run. Same table family, different lifetimes and different callers.
 *
 * @see DerivedStreamHead
 * @see \Storm\Projector\Link\EventLinkWriter::deleteLinks()
 * @see \Storm\Projector\Run\ProjectionRunner the run-start gate consuming this
 */
interface DerivedStreamRevision
{
    /**
     * The membership revision of `$target`, or 0 when its links have never been destructively rebuilt.
     */
    public function revisionFor(StreamName $target): int;
}
