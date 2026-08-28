<?php

declare(strict_types=1);

namespace Storm\EventLinks\Schema;

/**
 * Raw PostgreSQL DDL for `event_links`: the derived/projection-stream link bookkeeping written by a
 * `LinkProjection` via `EventLinkWriter`.
 *
 * A link records that a source event, `source_sequence` being its global `event_store.sequence_no`, was
 * projected into a `target_stream` at a dense `target_position`.
 *
 * - `PRIMARY KEY (target_stream, target_position)`: dense, ordered positions per target stream, and
 *   the read pattern of both derived-stream filters. The natural key IS the identity; a surrogate id
 *   would cost a sequence and a third index on the link write path with no reader to serve.
 *
 * - `UNIQUE (target_stream, source_sequence)`: idempotency backstop, one link per source per target, the
 *   writer's `ON CONFLICT` relying on it. Cheap, and it eases a future replica-read mode.
 *
 * The per-target membership REVISION, what tells a consumer its stream was destructively rebuilt under
 * its checkpoint, lives in the sibling `event_link_streams`, its own table and its own schema class; the
 * links here are the stream's content, that is the stream's identity.
 *
 * No FK to `event_store`: `sequence_no` is globally unique via one identity sequence, and a foreign key
 * onto a partitioned table brings its own warnings. Orphan links DO exist by design, since erasing a
 * stream leaves the projector-owned bookkeeping standing; they are harmless to the fold, whose join
 * yields nothing for them, and the head read counts them, so a derived head can sit above what the
 * join returns. Adding the FK on the strength of "there are no orphans" would break the eraser the
 * first time it runs.
 *
 * Deliberately events-side under the read-model store split, with no CONNECTION_SIDE constant unlike
 * `ProjectionSchema`, because a derived stream is read by JOINING `event_links` with `event_store`, so
 * the two tables must share a database. Under the split, a link projection and its checkpoint are
 * therefore HOMED events-side together, as `ProjectionHome` decides and `ReadModelStoreSplitTest`
 * verifies, keeping the link write and the checkpoint advance in one transaction on the events
 * connection; the derived consumer folds store-side by join-reading the events database. Link
 * projections are not refused under the split.
 *
 * @see \Storm\Projector\Definition\LinkProjection
 * @see \Storm\Projector\Link\EventLinkWriter
 */
final class EventLinkSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS event_links (
                    target_stream   text   NOT NULL,
                    target_position bigint NOT NULL,
                    source_sequence bigint NOT NULL,
                    linked_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT event_links_pk PRIMARY KEY (target_stream, target_position),
                    CONSTRAINT event_links_source_uq UNIQUE (target_stream, source_sequence)
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS event_links',
        ];
    }
}
