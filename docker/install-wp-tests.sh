#!/usr/bin/env bash
# Install WordPress core and the WordPress PHPUnit test library for a given version.
# Usage: install-wp-tests.sh <version|latest>
# The database configuration is read from the environment at test time (see wp-tests-config.php).
set -euo pipefail

WP_VERSION="${1:-latest}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"

if [ "$WP_VERSION" = "latest" ]; then
	# Resolve the newest stable release number.
	WP_VERSION="$(curl -fsSL 'https://api.wordpress.org/core/version-check/1.7/' | grep -o '"version":"[0-9.]*"' | head -1 | sed 's/"version":"\([0-9.]*\)"/\1/')"
	echo "Resolved latest WordPress: ${WP_VERSION}"
fi

# Version tags exist as "6.0", "6.0.11", "6.9". Tarballs for the first release of a branch are "6.0".
TAG="tags/${WP_VERSION}"
mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
rm -f /tmp/wordpress.tar.gz

svn export --quiet --force --ignore-externals "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn export --quiet --force --ignore-externals "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

cp /opt/ci/wp-tests-config.php "$WP_TESTS_DIR/wp-tests-config.php"

echo "$WP_VERSION" > /opt/ci/wp-version.txt
echo "WordPress ${WP_VERSION} and its test library are installed."
