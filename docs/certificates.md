---
title: Setting up signing credentials
weight: 12
---

Each service needs its own key and certificate. All services trust the same certificate authority (CA).

## Generating a key pair

Create the cluster certificate authority once:

```bash
# Once, wherever certificates get issued — never on a running service:
php artisan microservice:authority:generate
```

Set `SERVICE_CA_PUBLIC_KEY` to the generated public key on every service.

> [!IMPORTANT]
> Keep the CA private key off running services. It is only used to issue certificates.

Each service then generates its own pair and gets it certified:

```bash
# On the service itself:
php artisan microservice:keygen

# Wherever the CA private key lives, using the public key microservice:keygen printed:
php artisan microservice:certificate:issue catalog base64-catalog-public-key
```

`microservice:keygen` writes `SERVICE_PRIVATE_KEY` to `.env` and prints the public key. Copy the issued certificate into `SERVICE_CERTIFICATE`.

To rotate a service key, run `microservice:keygen`, issue a new certificate, and deploy both to that service.

## Issuing certificates for a whole cluster

Generate keys and certificates for several services in one command:

```bash
php artisan microservice:certificate:issue --for=catalog --for=orders --for=sites
```

The command prompts for the CA private key once and prints each service's private key and certificate. Supply an existing public key to issue only a certificate:

```bash
php artisan microservice:certificate:issue --for=catalog --for=orders:base64-orders-public-key
```

Or use a file with one service and optional public key per line:

```
# cluster.txt
catalog
orders base64-orders-public-key
sites
```

```bash
php artisan microservice:certificate:issue --manifest=cluster.txt
```

A target with an invalid public key is skipped with a warning rather than aborting the whole batch.

For existing services, generate replacement keys on the service with `microservice:keygen`.

See [verifying service requests](security.md#trust-peer) to protect your routes.
