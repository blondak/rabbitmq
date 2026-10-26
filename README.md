# RabbitMQ
 RabbitMQ (AMQP, STOMP, MQTT) integration for Nette Framework using BunnyPHP

<p>
  <a href="https://github.com/bckp/rabbitmq/actions"><img src="https://img.shields.io/github/actions/workflow/status/bckp/rabbitmq/main.yaml?style=flat-square"></a>
  <a href="https://coveralls.io/r/bckp/rabbitmq"><img src="https://img.shields.io/coverallsCoverage/github/bckp/rabbitmq?style=flat-square"></a>
  <a href="https://packagist.org/packages/bckp/rabbitmq"><img src="https://img.shields.io/packagist/dm/bckp/rabbitmq?style=flat-square"></a>
  <a href="https://github.com/bckp/rabbitmq"><img src="https://img.shields.io/github/license/bckp/rabbitmq?style=flat-square"></a>
  <a href="https://packagist.org/packages/bckp/rabbitmq"><img src="https://img.shields.io/packagist/dependency-v/bckp/rabbitmq/php?style=flat-square"></a>
  <a href="https://github.com/bckp/rabbitmq/issues"><img src="https://img.shields.io/github/issues/bckp/rabbitmq?style=flat-square" alt="Issues"/></a>
  <a href="https://packagist.org/packages/bckp/rabbitmq"><img src="https://img.shields.io/packagist/v/bckp/rabbitmq?style=flat-square"></a>
</p>

## Usage

To install the latest version of `bckp/rabbitmq` use [Composer](https://getcomposer.org).

```
$ composer require bckp/rabbitmq
```

## Documentation

For details on how to use this package, check out our [documentation](.docs).

## Versions

| State  | Version   | Branch   | Nette    | PHP     |
|--------|-----------|----------|----------|---------|
| dev    | `^11.2.x` | `master` | `3.0+`   | `8.0–8.4` |
| stable | `11.1.6-pl2` | tag   | `3.0+`   | `8.0–8.4` |

### PHP 8.4 compatibility (blondak fork)

The `11.1.6-pl2` release retains the Nette DI 3.0 compatibility of
`11.1.6-pl1` and adds PHP 8.4 to the supported and tested versions.
Install this fork through a Composer VCS repository pointing to
`https://github.com/blondak/rabbitmq`; the Composer package name remains
`bckp/rabbitmq`.

Run unit tests with `make tests`. To also run integration tests, set
`RABBITMQ_HOST` to a disposable RabbitMQ broker. Optional settings are
`RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` and `RABBITMQ_VHOST`.
Defaults are port 5672, guest/guest and vhost `/`. Tests create randomly
named queues and exchanges and remove them afterwards. CI runs these tests
against its own RabbitMQ service on PHP 8.0 through 8.4.

## Development

This package is currently maintaining by these authors.

<a href="https://github.com/bckp"><img width="80" height="80" src="https://avatars.githubusercontent.com/u/179652?v=4&s=80"></a>

-----

This package is FORK of **contributte/rabbitmq** to better fit our needs, and because original author have limited amount of time to support it.
Also thank you for using this package.
