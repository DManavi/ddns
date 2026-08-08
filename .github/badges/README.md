# Badge data

`coverage.json` is a [shields.io endpoint][endpoint] document, fetched from
`raw.githubusercontent.com` by the coverage badge in the project README.

It is **generated, not hand-edited**. The `coverage` job in
`.github/workflows/ci.yml` measures line coverage on every push to `main` and
commits this file when the number changes. It starts as `unknown` because
coverage cannot be measured without a coverage driver, and the first run on
`main` is what replaces it with a real figure.

To reproduce what CI does:

```bash
composer test:coverage
php .github/scripts/coverage-badge.php \
    --clover=build/coverage.xml \
    --badge=.github/badges/coverage.json
```

That needs pcov or Xdebug in the PHP running it; without one PHPUnit reports no
coverage driver and writes nothing.

[endpoint]: https://shields.io/badges/endpoint-badge
