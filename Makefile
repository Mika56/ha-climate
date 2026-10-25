UID := $(shell id -u)
GID := $(shell id -g)
PHP_IMAGE := ha-climate-php-dev:8.5
NODE_IMAGE := node:24.22.1-alpine

DOCKER_PHP = docker run --rm --user $(UID):$(GID) -e HOME=/workspace/.cache -v $(PWD):/workspace -w /workspace/app $(PHP_IMAGE)
DOCKER_NODE = docker run --rm --user $(UID):$(GID) -e HOME=/workspace/.cache -e npm_config_cache=/workspace/.cache/npm -v $(PWD):/workspace -w /workspace/frontend $(NODE_IMAGE)

.PHONY: dev-image php-install php-ci cs-fix stan test front-install front-ci ci sh-php sh-node

dev-image:
	docker build -t $(PHP_IMAGE) docker/dev/php

php-install: dev-image
	mkdir -p .cache/composer
	$(DOCKER_PHP) composer install --no-interaction --prefer-dist

php-ci: php-install
	mkdir -p .cache/composer
	$(DOCKER_PHP) composer ci

cs-fix: dev-image
	$(DOCKER_PHP) composer cs:fix

stan: dev-image
	$(DOCKER_PHP) composer stan

test: dev-image
	$(DOCKER_PHP) composer test

front-install:
	mkdir -p .cache/npm
	$(DOCKER_NODE) npm ci

front-ci:
	mkdir -p .cache/npm
	$(DOCKER_NODE) sh -c "npm ci && npm run ci"

ci: php-ci front-ci

sh-php: dev-image
	docker run --rm -it --user $(UID):$(GID) -e HOME=/workspace/.cache -v $(PWD):/workspace -w /workspace/app $(PHP_IMAGE) bash

sh-node:
	docker run --rm -it --user $(UID):$(GID) -e HOME=/workspace/.cache -e npm_config_cache=/workspace/.cache/npm -v $(PWD):/workspace -w /workspace/frontend $(NODE_IMAGE) sh
