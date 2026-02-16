# Jurager/Microservice

[![Latest Stable Version](https://poser.pugx.org/jurager/microservice/v/stable)](https://packagist.org/packages/jurager/microservice)
[![Total Downloads](https://poser.pugx.org/jurager/microservice/downloads)](https://packagist.org/packages/jurager/microservice)
[![PHP Version Require](https://poser.pugx.org/jurager/microservice/require/php)](https://packagist.org/packages/jurager/microservice)
[![License](https://poser.pugx.org/jurager/microservice/license)](https://packagist.org/packages/jurager/microservice)

A Laravel package for secure and resilient HTTP communication between microservices.

Features:

- HMAC-signed requests for internal service authentication
- Automatic retries and failover across multiple instances
- Redis-based health tracking to avoid unhealthy nodes
- Route discovery for gateway proxying
- Idempotency support for non-safe requests (POST, PUT, PATCH)
- Built for production environments where reliability and consistency matter.



- [Requirements](#requirements)
- [Installation](#installation)
- [License](#license)

## Requirements

- PHP 8.2+
- Laravel 11+
- Redis
- Guzzle 7+

## Installation

To install, configure and learn how to use please go to the [Documentation](https://docs.gerassimov.me/microservice/).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
