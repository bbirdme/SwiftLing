# SwiftLing

SwiftLing makes a WordPress site multilingual. It finds the texts of a page as visitors see them, you translate them in one editor beside the original, and each language gets an address of its own. Your pages are not copied for every language, and machine translation uses your own key.

- Website and documentation: https://swiftling.pro
- Download: https://swiftling.pro/download/ (until it is listed on WordPress.org)
- Licence: GPL-2.0-or-later, see [LICENSE](LICENSE)

## Contributing

Bug reports, ideas and pull requests are welcome, see [CONTRIBUTING.md](CONTRIBUTING.md). Everyone can suggest; only the maintainer merges.

## For developers

## Setup

```bash
git clone https://github.com/bbirdme/SwiftLing.git swiftling
cd swiftling
composer install
```

Clone into a folder named `swiftling` (lowercase). WordPress derives the plugin slug from the folder name, and the slug must match the text domain `swiftling`.


## Checks

- `composer lint` runs PHPCS with the WordPress Coding Standards; `composer lint:fix` fixes what it can.
- `composer test` runs the tests, see below.
- CI runs on every push:
  - PHPCS;
  - the tests, on PHP 7.4 and the latest PHP;
  - Plugin Check on the package built with `rsync --exclude-from=.distignore`, which contains exactly the files that would ship to WordPress.org;
  - a check that no development files are in that package.

## Tests

The tests run in WordPress's own test suite (`wp-phpunit`), with the WordPress that `composer install` puts in `vendor/`. They need a MySQL database that they empty on every run, so never point them at a real site. Start one in Docker:

```bash
docker run -d --name swiftling-tests-db -p 127.0.0.1:33061:3306 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress_tests mysql:8.4
```

Then:

```bash
composer test
```

For another database, set `WP_TESTS_DB_HOST`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER` and `WP_TESTS_DB_PASSWORD`; the defaults are in `tests/wp-tests-config.php`. The tests never reach the internet: a test that needs an answer from a translation service gives it itself.

A page check normally runs in the editor's browser. The tests do what the browser reports with `TestCase::check()`: it reads a page's values in probe mode and reports every marker as visible text, unless a test says where a marker landed. That way a test can check a page, translate its texts and look at the page in another language without a browser.

## Hooks

Other plugins, SwiftLing Pro first among them, build on the hooks described in
[docs/hooks.md](docs/hooks.md). `tests/HooksTest.php` keeps that list and the code in step.

## Packaging

`.distignore` lists everything that must not ship. Add every new development-only file or folder (configuration, tests, tooling) to it. Keep its patterns simple (a leading `/` anchors to the plugin root, a bare name matches anywhere), because rsync reads the same file. CI fails if hidden files, `tests` or `node_modules` end up in the package.

## Local environment with DDEV

Keep this repository where it is and run WordPress in a separate DDEV project that mounts it as the plugin, so every saved change is live without copying or pulling.

```bash
mkdir -p ~/sites/swiftling-dev && cd ~/sites/swiftling-dev
ddev config --project-type=wordpress
```

Add two files to that project's `.ddev/` folder. `docker-compose.swiftling.yaml` mounts the repository:

```yaml
services:
  web:
    volumes:
      - /absolute/path/to/swiftling:/var/www/html/wp-content/plugins/swiftling
```

`config.swiftling.yaml` creates the mount point before the containers start. Without it Docker creates `wp-content/` as root, and WordPress cannot write themes, plugins or uploads:

```yaml
hooks:
  pre-start:
    - exec-host: mkdir -p wp-content/plugins/swiftling
```

Then:

```bash
ddev start
ddev wp core download
ddev wp core install --url='$DDEV_PRIMARY_URL' --title='SwiftLing Dev' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev wp plugin activate swiftling
```
