#!/usr/bin/env bash

php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/bin --filename=composer
rm composer-setup.php
