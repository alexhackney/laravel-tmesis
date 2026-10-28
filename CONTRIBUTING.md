# Contributing

Thanks for helping with `alexhackney/laravel-tmesis`.

## Setup

```sh
composer install
composer test      # Pest
composer analyse   # PHPStan level 8 with Larastan
composer format    # Pint (Laravel preset, strict types)
```

Pint, PHPStan and the test suite must all pass. Add a test with every change, including
the exception paths, and never call a live node from a test: use `Http::fake()` or
`FakeTmesis`.

## The contract suite

The package is built against Tmesis's generated contract artifacts, which are bundled
under `tests/Fixtures/contract/` with their hashes in `PROVENANCE.md`. Please do not
edit those files by hand; they are copied by `bin/repin` from a Tmesis checkout. The
bundled legs (`composer test`) are what a contribution needs to pass.

See [docs/contract-suite.md](docs/contract-suite.md) and
[docs/development.md](docs/development.md) for the details.

## Pull requests

- Keep a change focused, and describe the behaviour it changes.
- Add an entry under `## [Unreleased]` in `CHANGELOG.md` (create the heading if it is
  missing). Do not rename it to a version; releases are cut by the maintainers.
- Use conventional commit messages (`fix:`, `feat:`, `docs:`).

By contributing you agree that your work is released under the MIT licence.
