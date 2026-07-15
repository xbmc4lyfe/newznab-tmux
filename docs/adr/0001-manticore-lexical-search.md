# ADR 0001: Manticore lexical search and maintenance rebuilds

## Status

Accepted

## Context

NNTmux searches release and metadata tables through Manticore. Search must remain predictable for Newznab clients, tolerate release-name punctuation and misspellings, and remain operable without embedding services. Existing release browsing is newest-first and callers rely on that ordering.

## Decision

- Pin Manticore Search 28.4.4 and keep the official PHP client at 4.0.1.
- Use one table registry for schemas and per-table lexical field weights.
- Retain `sph04`, quoted phrases, exclusions, exact-word indexing, and exact-first/fuzzy-second retrieval.
- Keep release results ordered by the requested attribute, defaulting to `postdate_ts DESC, id DESC`; lexical score controls matching rather than presentation order.
- Rebuild incompatible tables in a maintenance window. Semantic, conversational, vector, and external-embedding search are deferred.

## Consequences

The deployment stays lightweight and backward compatible, and relevance changes can be measured with a stable fixture. Ranking cannot rescue an older result from date-first presentation. Settings that affect indexed tokens require a complete repopulation and a backup-based rollback.
