## artack/recaptcha-enterprise-bundle — everything runs in Docker, no local PHP needed.
##
## Composer runs inside the PHP_VERSION image, so dependencies are resolved for the PHP version
## they will actually run on. The default is the lowest supported stack (PHP 8.1 with the lowest
## allowed dependency versions), which is what proves the declared requirements hold.
## Use `make update-latest`, or `PHP_VERSION=8.4`, to work against a newer stack.

PHP_VERSION ?= 8.1
IMAGE ?= artack-recaptcha-enterprise-bundle:php$(PHP_VERSION)
STAMP := .make/image-$(PHP_VERSION)

# The QA tools are pinned by tools/*/composer.lock, which needs a recent PHP. They analyse the
# code rather than run it, and phpstan.neon pins the analysed PHP range, so this is independent
# of PHP_VERSION.
TOOLS_PHP_VERSION ?= 8.4
TOOLS_IMAGE ?= artack-recaptcha-enterprise-bundle:php$(TOOLS_PHP_VERSION)
TOOLS_STAMP := .make/image-$(TOOLS_PHP_VERSION)

COMPOSER_CACHE ?= $(HOME)/.cache/composer

# Files are written back to the host, so the container must run as the current user.
USER_ID := $(shell id -u)
GROUP_ID := $(shell id -g)

DOCKER_RUN = docker run --rm --init --user $(USER_ID):$(GROUP_ID) -v "$(CURDIR)":/app -w /app
COMPOSER_ENV = -e COMPOSER_HOME=/tmp/composer -v "$(COMPOSER_CACHE)":/tmp/composer
COMPOSER = $(DOCKER_RUN) $(COMPOSER_ENV) $(IMAGE) composer
COMPOSER_TOOLS = $(DOCKER_RUN) $(COMPOSER_ENV) $(TOOLS_IMAGE) composer
PHPUNIT = $(DOCKER_RUN) $(IMAGE) vendor/bin/phpunit
PHPSTAN = $(DOCKER_RUN) $(TOOLS_IMAGE) tools/phpstan/vendor/bin/phpstan
PHP_CS_FIXER = $(DOCKER_RUN) $(TOOLS_IMAGE) tools/php-cs-fixer/vendor/bin/php-cs-fixer

.DEFAULT_GOAL := help
.PHONY: help build install install-tools update update-latest test phpstan cs cs-fix qa shell clean

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

build: $(STAMP) ## Build the PHP $(PHP_VERSION) development image

install: vendor install-tools ## Install every dependency (bundle + tools)

install-tools: tools/phpstan/vendor tools/php-cs-fixer/vendor ## Install the PHPStan and PHP-CS-Fixer tool dependencies

update: $(STAMP) ## Resolve the lowest supported dependency versions
	@mkdir -p "$(COMPOSER_CACHE)"
	$(COMPOSER) update --prefer-lowest --prefer-stable --no-interaction --no-progress
	@touch vendor

update-latest: $(STAMP) ## Resolve the newest dependency versions supported by PHP_VERSION
	@mkdir -p "$(COMPOSER_CACHE)"
	$(COMPOSER) update --prefer-stable --no-interaction --no-progress
	@touch vendor

test: vendor ## Run the test suite
	$(PHPUNIT) --colors=always $(ARGS)

phpstan: vendor tools/phpstan/vendor $(TOOLS_STAMP) ## Run the static analysis
	$(PHPSTAN) analyse -c phpstan.neon --memory-limit=1G --ansi $(ARGS)

cs: vendor tools/php-cs-fixer/vendor $(TOOLS_STAMP) ## Check the coding standards
	$(PHP_CS_FIXER) fix --dry-run --diff --allow-risky=yes --ansi $(ARGS)

cs-fix: vendor tools/php-cs-fixer/vendor $(TOOLS_STAMP) ## Fix the coding standards
	$(PHP_CS_FIXER) fix --allow-risky=yes --ansi $(ARGS)

qa: phpstan cs test ## Run the full quality suite

shell: $(STAMP) ## Open a shell in the development container
	@$(DOCKER_RUN) -it $(IMAGE) bash

clean: ## Remove the installed dependencies, the caches and the image stamps
	rm -rf vendor tools/phpstan/vendor tools/php-cs-fixer/vendor .phpunit.cache .php-cs-fixer.cache .make

# composer.lock is not committed (this is a library), so dependencies are always resolved.
vendor: composer.json $(STAMP)
	@mkdir -p "$(COMPOSER_CACHE)"
	$(COMPOSER) update --prefer-lowest --prefer-stable --no-interaction --no-progress
	@touch vendor

tools/phpstan/vendor: tools/phpstan/composer.json tools/phpstan/composer.lock $(TOOLS_STAMP)
	@mkdir -p "$(COMPOSER_CACHE)"
	$(COMPOSER_TOOLS) install --working-dir tools/phpstan --no-interaction --no-progress --no-scripts
	@touch tools/phpstan/vendor

tools/php-cs-fixer/vendor: tools/php-cs-fixer/composer.json tools/php-cs-fixer/composer.lock $(TOOLS_STAMP)
	@mkdir -p "$(COMPOSER_CACHE)"
	$(COMPOSER_TOOLS) install --working-dir tools/php-cs-fixer --no-interaction --no-progress --no-scripts
	@touch tools/php-cs-fixer/vendor

# One stamp per PHP version; the pattern covers both $(IMAGE) and $(TOOLS_IMAGE).
.make/image-%: docker/Dockerfile
	docker build --build-arg PHP_VERSION=$* --tag artack-recaptcha-enterprise-bundle:php$* docker
	@mkdir -p $(dir $@)
	@touch $@
