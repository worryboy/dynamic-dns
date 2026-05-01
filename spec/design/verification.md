# Verification Notes

Provider-side validation is the primary check for this worker. The current InterNetX XML implementation reads the provider zone with ZoneInfo before deciding whether a live update is needed.

A later verification feature should stay lightweight and staged:

1. Provider check: read the authoritative provider state after update when the provider interface supports it.
2. Optional public resolver check: query configured public resolvers to see whether the new value is visible outside the provider.
3. Optional local resolver check: query the local resolver only as an operator convenience.

Local DNS lookup alone is not enough to prove propagation or provider-side correctness. Public resolver checks should be configurable because TTLs, resolver caches, and split DNS can make immediate results noisy.
