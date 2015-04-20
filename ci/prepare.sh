#!/bin/bash

# called by Travis CI

# Exit if anything fails AND echo each command before executing
# http://www.peterbe.com/plog/set-ex
set -ex

echo 'date.timezone = "Europe/London"' >> ~/.phpenv/versions/$(phpenv version-name)/etc/conf.d/travis.ini

# Set up the Apache virtualhost
echo $TRAVIS_BUILD_DIR
mkdir -p $TRAVIS_BUILD_DIR/wordpress-site/
cp wordpress.conf /etc/apache2/sites-available/
a2ensite apache-ci.conf
sudo service apache2 restart

# Set up the database
mysql -e 'CREATE DATABASE wp_test;' -uroot
mysql -e 'GRANT ALL PRIVILEGES ON wp_test.* TO "wp_cli_test"@"localhost" IDENTIFIED BY "password1"' -uroot
