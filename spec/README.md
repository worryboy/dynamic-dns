# Dynamic DNS Spec

This area is for shared Dynamic DNS behavior that should apply across product shapes.

The spec is not worker-specific and not endpoint-specific. It should hold:

- behavior expectations
- scenarios
- fixtures
- provider-neutral contracts
- verification notes
- future test cases shared by worker and endpoint implementations

Current starter docs:

- [Verification notes](design/verification.md)

## Versioning

The spec has its own version line in [VERSION](VERSION).

Recommended approach:

- bump spec versions when shared behavior contracts or fixtures change
- do not bump spec just because the worker or endpoint implementation changes
- reference spec versions from worker/endpoint release notes when behavior compatibility matters
