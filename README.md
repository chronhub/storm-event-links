# Storm EventLinks

The link bookkeeping behind derived streams: the two tables a `LinkProjection` writes into, the two
read filters that turn those links back into an ordered event stream, and the two ports a consumer
needs to fold one safely.

A link records that a source event — identified by its global `event_store.sequence_no` — was
projected into a `target_stream` at a dense `target_position`. The links are the derived stream's
*content*; a small sibling table carries its *identity*.

> PostgreSQL is assumed, not abstracted: the read side is a join against `event_store`, so the two
> tables are homed events-side and share a database with it.

## Install

```bash
composer require chronhub/storm-event-links
```

## What's inside

| Class | Role |
|-------|------|
| `EventLinkSchema` | DDL for `event_links`: one row per source event linked into a target |
| `EventLinkStreamSchema` | DDL for `event_link_streams`: the per-target membership revision |
| `DerivedStreamFilter` | Ad-hoc browse of a derived stream, ordered by `target_position` |
| `DerivedStreamProjectionFilter` | The checkpointed fold read, ordered and resumed on `sequence_no` |
| `DerivedStreamHead` / `DbalDerivedStreamHead` | The frontier port: the highest source position actually linked |
| `DerivedStreamRevision` / `DbalDerivedStreamRevision` | The identity port: how many times the links were destructively rebuilt |

## Two ports, deliberately not one

They read the same table family and are still separate interfaces, because they answer questions
with different lifetimes:

- **`DerivedStreamHead` is a frontier.** It moves with every link and is read once per batch. A
  derived consumer must never advance past the last position its producer actually linked. Bounding
  the scan by the event-store safe head instead would let a consumer scheduled ahead of its producer
  checkpoint past positions that are not linked *yet*, and every late link would fall permanently
  below its checkpoint. Behind the head, un-linked positions are legitimate and folded as a tail;
  ahead of it, nothing is decided — so wait, never skip.

- **`DerivedStreamRevision` is an identity.** It changes only on a destructive rebuild and is read
  once per run. A consumer's checkpoint claims "everything linked up to here is folded", which is an
  assertion about a *set*, not a position — and a rebuild can change that set *below* the checkpoint
  without moving it. Nothing in the stream's own shape reveals it: `max(source_sequence)` is
  unchanged or higher, and target positions are dense either way. The revision is the missing
  witness, stamped on the consumer's row while its checkpoint is still 0 and compared on every later
  run.

## Reading a derived stream

Two filters, two jobs:

```
DerivedStreamFilter            ORDER BY l.target_position   -- browse, link-write order, unbounded
DerivedStreamProjectionFilter  ORDER BY e.sequence_no       -- fold, checkpoint < seq <= safe head
```

`DerivedStreamFilter` needs no safe-head watermark: a derived stream is gap-free by construction,
since a single-lease `LinkProjection` assigns dense `target_position`s inside one transaction, so
unlike a category read it can never see an in-flight gap below the head.

`DerivedStreamProjectionFilter` resumes on the global `sequence_no` instead, which a single-producer
derived stream is monotone with — so the projector's checkpoint, safe-head watermark and `scanMax`
all apply unchanged.

Either way the yielded `EventRecord` still carries its global `sequence_no` as its position;
`target_position` is only the ordering key *within* the derived stream.

## Design decisions

- **No foreign key to `event_store`.** `sequence_no` is globally unique through one identity
  sequence and the store is append-only, so orphan links cannot exist; this also avoids the
  partitioned-table FK warnings.
- **Two tables, not one.** The links are the stream's content, the revision is the stream's
  identity, and they change on entirely different clocks.
- **A revision row appears on the first destructive rebuild**, never on ordinary linking — so a
  stream that was never rebuilt has no row and reads 0, the same value a fresh consumer stamps,
  which is why the absent row needs no backfill.
- **The bump and the delete share one statement**, a data-modifying CTE in `EventLinkWriter`, so the
  revision and the links it describes cannot drift apart.
- **No resume cursor on `target_position`.** No current reader needs it, and the checkpoint
  semantics a folding consumer would want — `target_position` versus the global `sequence_no` — are
  undecided; it lands when that consumer does.

## Tests

```bash
vendor/bin/phpunit src/EventLinks/Tests   # from the storm root
```

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
