#!/bin/bash

# called by Travis CI

# Exit if anything fails AND echo each command before executing
# http://www.peterbe.com/plog/set-ex
set -ex

echo 'date.timezone = "Europe/London"' >> ~/.phpenv/versions/$(phpenv version-name)/etc/conf.d/travis.ini

sudo apt-get install apache2 libapache2-mod-fastcgi

# set up WordPress site directory
WORDPRESS_SITE_DIR="$(dirname $TRAVIS_BUILD_DIR)/wordpress/"
echo "Site dir $WORDPRESS_SITE_DIR"
mkdir -p $WORDPRESS_SITE_DIR

# enable php-fpm
sudo cp ~/.phpenv/versions/$(phpenv version-name)/etc/php-fpm.conf.default ~/.phpenv/versions/$(phpenv version-name)/etc/php-fpm.conf
sudo a2enmod rewrite actions fastcgi alias
echo "cgi.fix_pathinfo = 1" >> ~/.phpenv/versions/$(phpenv version-name)/etc/php.ini
~/.phpenv/versions/$(phpenv version-name)/sbin/php-fpm

# configure apache virtual hosts
sudo cp -f $TRAVIS_BUILD_DIR/ci/wordpress-apache.conf /etc/apache2/sites-available/default
sudo sed -e "s?%WORDPRESS_SITE_DIR%?${WORDPRESS_SITE_DIR}?g" --in-place /etc/apache2/sites-available/default
cat /etc/apache2/sites-available/default
sudo service apache2 restart

# Set up the database
mysql -e 'CREATE DATABASE wordpress;' -uroot
mysql -e 'GRANT ALL PRIVILEGES ON wordpress.* TO "wordpress"@"localhost" IDENTIFIED BY "password"' -uroot

# install WordPress
cd $WORDPRESS_SITE_DIR
./bin/wp core download --version=$WP_VERSION
# @TODO Set WP_DEBUG and test for notices, etc
./bin/wp core config --dbname=wordpress --dbuser=wordpress --dbpass=password
./bin/wp core install --url=wordpress.dev --title="WordPress Testing" --admin_user=admin --admin_password=password --admin_email=testing@example.invalid
cp -pr $TRAVIS_BUILD_DIR $WORDPRESS_SITE_DIR/wp-content/plugins/
ls -al $WORDPRESS_SITE_DIR/wp-content/plugins/

# Now check
pwd
ls -alh
curl -s http://wordpress.dev/
