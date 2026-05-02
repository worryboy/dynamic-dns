# Endpoint Architecture Notes

The endpoint is reserved for a future inbound Dynamic DNS service.

It should not inherit the worker's outbound-only runtime assumptions or the future client's URL-calling runtime assumptions. When implemented, it will need its own:

- HTTP runtime
- authentication model
- request validation
- abuse controls
- provider update orchestration
- response format

For now this document is a boundary marker. No endpoint runtime is implemented.
