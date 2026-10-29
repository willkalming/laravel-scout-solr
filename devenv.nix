{
  pkgs,
  lib,
  config,
  inputs,
  ...
}:

{
  # https://devenv.sh/basics/
  env.GREET = "laravel-scout-solr";

  # https://devenv.sh/packages/
  packages = [ pkgs.git ];

  # https://devenv.sh/languages/
  # PHP 8.5 is the current stable release and the version v3 targets.
  # The default `pkgs.php` in our nixpkgs pin is PHP 8.5.
  languages.php.enable = true;
  languages.php.extensions = [
    "curl"
    "dom"
    "mbstring"
    "openssl"
    "pcntl"
    "pdo"
    "pdo_sqlite"
    "sqlite3"
    "tokenizer"
    "xmlwriter"
  ];
  languages.php.ini = ''
    memory_limit = 1G
  '';

  # Composer for dependency management
  languages.php.packages.composer = pkgs.phpPackages.composer;

  # https://devenv.sh/scripts/
  scripts.test.exec = "vendor/bin/phpunit --colors=always $@";
  scripts.test-unit.exec = "vendor/bin/phpunit --colors=always --testsuite Unit $@";
  scripts.test-regression.exec = "vendor/bin/phpunit --colors=always --testsuite Regression $@";

  scripts.composer-deps.exec = ''
    # Install the dependencies for the *current* (pre-upgrade) codebase.
    # The library still declares `php: ^7.1` at this point, so platform checks
    # are relaxed for the php requirement while we run on PHP 8.5.
    composer update \
      --no-interaction \
      --ignore-platform-req=php \
      --with='laravel/scout:^7.0' \
      --with='illuminate/support:^7.0' \
      --with='illuminate/database:^7.0' \
      --with='illuminate/pagination:^7.0' \
      --with='illuminate/contracts:^7.0' \
      --with='phpunit/phpunit:^9.6' \
      --with='mockery/mockery:^1.6'
  '';

  scripts.composer-deps-upgrade.exec = ''
    # Install dependencies for the upgraded codebase (PHP 8.5 / Scout 11 / Solarium 7).
    composer update --no-interaction
  '';

  enterShell = ''
    echo "laravel-scout-solr devenv"
    php --version | head -n 1
    composer --version
  '';

  # https://devenv.sh/tests/
  enterTest = ''
    composer test || vendor/bin/phpunit --colors=always
  '';
}
