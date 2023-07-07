# Backup CLI for Eddy Server Management

This app is meant to be used with [Eddy Server Management](https://eddy.management). It is not recommended to use this app without it.

## Requirements

- PHP 8.1 or higher

## Installation

You can install the package via composer:

```bash
composer global require protonemedia/eddy-backup-cli
```

## Usage

You can run the backup command by running the following command:

```bash
composer global exec eddy-backup-cli backup:run {url}
```