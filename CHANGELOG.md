# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.1 Under development

- fix(docs): warn against unsafe multipart body parser setup.
- fix: clear Yii core `uploaded-file` cache when resetting bridge uploaded-file state to prevent cross-request upload leakage in workers.
- chore: update dependencies and configuration files.
- fix(http): unregister previous error handler before reinit.

## 0.3.0 February 28, 2026

- feat: preserve configured worker singletons and persistent components (`db`, `cache`) across requests while keeping request-scoped components reinitialized per request in `Application`.
- refactor: simplify `Application` class by removing the unused container property and related methods; add `bootstrapContainer()` for container configuration and update documentation accordingly.
- refactor: simplify conditionals and remove redundant comments in `Application` for improved readability.
- test: remove redundant comments in `ApplicationConfigTest` and `ApplicationCoreTest` for improved clarity and maintainability.
- test: update unit tests for `Application` to enhance clarity and coverage; add `ApplicationReinitializationTest` for reinitialization behavior validation and update related documentation.
- fix: bootstrap the DI container during `Application` construction, remove per-request container bootstrap, and avoid masking initialization failures behind missing `errorHandler` secondary exceptions.

## 0.2.1 February 21, 2026

- docs: update the installation command to require version `0.2` of psr-bridge in `README.md` and `docs/installation.md`.
- refactor: remove automatic `statelessAppStartTime` header injection from `Request::setPsr7Request()` so runtime-specific workers can set it explicitly.

## 0.2.0 February 20, 2026

- refactor: change classes from `final` to non-`final` in `ErrorHandler`, `Request`, `Response`, `StatelessApplication`, and `UploadedFile` for improved extensibility.
- refactor: remove the worker mode property from `Request` and update related tests for consistency.
- refactor: rename `reset()` to `prepareForRequest()` in `StatelessApplication` and update the request handling call for clarity.
- refactor: extract `resetUploadedFilesState()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `reinitializeApplication()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `resetRequestState()` in `StatelessApplication` as a protected request-preparation hook.
- test: rename `ApplicationRestTest` to `ApplicationHookTest` to reflect its focus on lifecycle hooks.
- refactor: extract `prepareErrorHandler()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `attachPsrRequest()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `syncCookieValidationState()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `openSessionFromRequestCookies()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: extract `finalizeSessionState()` in `StatelessApplication` as a protected request-preparation hook.
- refactor: align lifecycle initialization by calling `parent::init()` and remove the manual `bootstrap()` call from `prepareForRequest()` in `StatelessApplication`.
- fix: resolve the script URL from PSR-7 server params in `Request::getScriptUrl()`, returning `SCRIPT_NAME` when available and an empty `string` in script-less worker mode when the adapter is set, for compatibility with PSR-7 request handling.
- fix: restore worker bootstrap timing by keeping `init()` lightweight and invoking `bootstrap()` in `prepareForRequest()` after PSR-7 request attachment in `StatelessApplication`.
- refactor!: rename `yii2\extensions\psrbridge\http\StatelessApplication` to `yii2\extensions\psrbridge\http\Application` and update all framework, docs, and tests references.
- refactor: simplify the `PAGE_NOT_FOUND` message in `Message` by removing `in Application.`.
- docs: standardize PHPUnit PHPDoc headers across `tests/support`, `tests/adapter`, `tests/http`, and `tests/provider`.
- docs: standardize PHPUnit PHPDoc headers across `src`.
- feat: add configurable lifecycle flags in `Application` (`useSession`, `resetUploadedFiles`, `syncCookieValidation`) with hook coverage tests and update documentation for worker lifecycle configuration.
- test: fix mutation-focused test coverage for the single `parseRequest()` invocation on missing routes.

## 0.1.5 January 28, 2026

- docs: update examples in `testing.md` for running Composer scripts with arguments.
- docs: update command syntax in `testing.md` to remove the redundant `run` prefix for Composer scripts.
- docs: update command syntax in `development.md` and `testing.md` for clarity and consistency.
- chore: remove the redundant ignore rule in `actionlint.yml` and update the Rector command in `composer.json` to drop the unnecessary `src` argument.

## 0.1.4 January 25, 2026

- chore: add `php-forge/coding-standard` to development dependencies for code quality checks and add support for `PHP 8.5`.
- chore: remove `FUNDING.yml`, update `.styleci.yml` and `README.md`, and add a development guide in `docs/development.md`.
- chore: update `php-forge/support` from `^0.2` to `^0.3`.

## 0.1.3 December 12, 2025

- fix: improve `ServerRequestAdapter` to handle body parsing and request adaptation logic, and update related tests.

## 0.1.2 December 11, 2025

- ci: update action versions to use `yii2-framework` for consistency.
- refactor: clean up test imports for consistency and add support files.
- docs: add copyright and license information to `MockerExtension` and refactor `tests/support/bootstrap.php`.
- chore: update `.editorconfig` and `.gitignore` for improved consistency and clarity.
- chore: update `symplify/easy-coding-standard` from `^12.5` to `^13.0`.
- test: add a test for memory usage at the `90%` threshold in `ApplicationMemoryTest`.
- fix: properly bridge PSR-7 `ServerRequestInterface` and Yii `Request`, including request body parsing.
- chore: add the memory limit option to the `mutation-static` command in `composer.json` for improved mutation testing.
- refactor: standardize error messages in `setPsr7Request()` and introduce comprehensive integration tests using `StatelessApplication`.
- docs: update `README.md` and `docs/configuration.md` to include automatic body parsing setup and parser configuration.
- chore: add the missing GitHub agents directory to the `.gitignore` ignore list.

## 0.1.1 October 6, 2025

- ci: add the `phpunit-dev` job to `build.yml` for enhanced testing capabilities.
- ci: add permissions to workflow files for enhanced access control.
- test: remove redundant cookie collection tests in `CookiesPsr7Test` for clarity.
- test: remove redundant cookie tests from `ResponseAdapterTest` for clarity.
- test: remove redundant remote IP and server port from `ServerParamsPsr7Test` for clarity.
- ci: consolidate `paths-ignore` configuration across workflow files for consistency, and add logos for badges in `README.md`.
- test: remove redundant test cases from `ServerRequestAdapterTest` for clarity.
- docs: correct a grammatical error in the docblock for `createErrorResponse()` in `ErrorHandler`.
- docs: update PHPDocs in tests for consistency and clarity.
- docs: add the mutation testing badge to `README.md` for enhanced visibility.

## 0.1.0 September 26, 2025

- feat: initial release.
