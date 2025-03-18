.PHONY: deploy install

deploy:
	ssh -A dcsm-commerce@ssh-dcsm-commerce.alwaysdata.net "cd www && git pull origin main && make install"

install: vendor/autoload.php
	php bin/console d:s:u -f
	composer dump-env prod
	php bin/console cache:clear

vendor/autoload.php: composer.json composer.lock
	composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-sodium
	composer require doctrine/doctrine-fixtures-bundle --ignore-platform-req=ext-sodium
	touch vendor/autoload.php