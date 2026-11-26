# TODO

## Keep the newer manifest during a rollout

`microservice:sync` pulls a manifest through the service address, which balances between pods. While a service with several replicas rolls out, old and new pods both answer, and a sync that reaches an old pod after a new one stores the older manifest again: routes the release added disappear from the gateway, and routes it removed come back, until the next sync. Everything settles within one sync interval after the rollout.

Add a release mark that only grows to the manifest, such as the CI job id from the pod annotation passed as `SERVICE_VERSION` through the Downward API (a rollback gets a new job id too), and have the sync keep a stored manifest whose mark is newer than the pulled one.
