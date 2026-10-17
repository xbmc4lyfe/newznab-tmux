---
applyTo: "**"
description: Use macOS or Linux host tools for development, builds, and tests
---

# Command execution (overrides Laravel Boost Sail)

This project's instructions **overrule** Boost's built-in Sail guideline that says you MUST run all commands through Sail.

## Default

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. Run PHP, Artisan, Composer, npm, Pint, PHPStan, and tests on the host:

```bash
php artisan test --compact --filter=TestName
./vendor/bin/pint --dirty
./vendor/bin/phpstan analyse --memory-limit=2G
npm run build
php artisan make:test --phpunit ExampleTest --no-interaction
```

Do **not** prefix routine commands with `vendor/bin/sail` or `./sail`.

## Sail / Docker only when needed

Use Sail or `just …` for stack lifecycle (`just up` / `just down`) or when the user explicitly asks for containerized commands.

## Docker diagnostics

Check Docker Desktop or the configured Linux VM on macOS. Check the Docker service and socket access on Linux. Host tools can run without Docker if their required services are reachable.

Canonical override source: `.ai/guidelines/sail/core.blade.php` (consumed by `php artisan boost:update`).
