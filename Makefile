.PHONY: deploy install

deploy:
	ssh -A dcsm-commerce@ssh-dcsm-commerce.alwaysdata.net "cd www && git pull origin main && make install"

install: vendor/autoload.php
	php bin/console d:s:u -f --no-interaction
	php bin/console asset-map:compile
	composer dump-env prod
	composer dump-autoload
	php bin/console cache:clear --no-warmup


vendor/autoload.php: composer.json composer.lock
	composer install --no-dev --optimize-autoloader
	touch vendor/autoload.php