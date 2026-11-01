# Thin wrapper over the composer scripts, so `make ci` works the same locally and in docs.
.PHONY: install test test-serial stan style length fix ci audit release-guards-test

install:
	composer install --no-interaction --prefer-dist

test:
	composer test

stan:
	composer stan

style:
	composer style

length:
	composer length

fix:
	vendor/bin/pint

audit:
	composer audit --locked

test-serial:
	composer test:serial

# Tests scripts/check-release.sh (the release workflow's guards) against a scratch git repository.
release-guards-test:
	vendor/bin/phpunit tests/Contract/ReleaseGuardTest.php tests/Contract/ReleaseWorkflowTest.php

ci: style stan test length release-guards-test
