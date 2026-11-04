# Jurager/Microservice

[![Latest Stable Version](https://poser.pugx.org/jurager/microservice/v/stable)](https://packagist.org/packages/jurager/microservice)
[![Total Downloads](https://poser.pugx.org/jurager/microservice/downloads)](https://packagist.org/packages/jurager/microservice)
[![PHP Version Require](https://poser.pugx.org/jurager/microservice/require/php)](https://packagist.org/packages/jurager/microservice)
[![License](https://poser.pugx.org/jurager/microservice/license)](https://packagist.org/packages/jurager/microservice)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-support-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/jurager01)

A Laravel package for secure communication between microservices.

- Signed HTTP requests and service discovery
- Gateway routes
- JSON:API resources and responses
- RabbitMQ events

```php
use Jurager\Microservice\Facades\Microservice;

$response = Microservice::service('catalog')->get('/v1/products')->send();
```

## Requirements

- PHP 8.4+
- Laravel 13.37+
- Cache (Redis, Memcached, DynamoDB, or Database)
- Guzzle 7+
- RabbitMQ 3.8+ (only when the event bus is used)

## Installation

```bash
composer require jurager/microservice
```

See [installation and setup](docs/installation.md). The full [documentation](docs/_index.md) is also available [online](https://docs.gerassimov.me/microservice/).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
