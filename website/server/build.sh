#!/bin/bash
set -e
set -x
#------------------------------------------------------------------------------
# Build the project for prod environment
# parameters are all forward to composer.
#------------------------------------------------------------------------------

# get composer
curl -sS https://getcomposer.org/installer | php
# get dependencies and build
./composer.phar install $@


# Dump js-routing
php app/console fos:js-routing:dump --target="./src/Ign/Bundle/OGAMConfigurateurBundle/Resources/public/js/fos_js_routes.js"

# install assets
php app/console assets:install

# Dump Assetic assets for prod environnement
php app/console assetic:dump --env=prod
php app/console cache:clear --env=prod
php app/console cache:warmup --env=prod

