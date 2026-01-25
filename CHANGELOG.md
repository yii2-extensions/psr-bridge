# ChangeLog

## 0.1.4 Under development

- Enh #202: Add `php-forge/coding-standard` to development dependencies for code quality checks and add support `PHP 8.5` (@terabytesoftw)
- Bug #203: Remove `FUNDING.yml`, update `.styleci.yml` and `README.md`, and add Development Guide in `docs/development.md` (@terabytesoftw)

## 0.1.3 December 12, 2025

- Bug #201: Improve `ServerRequestAdapter` class to handle body parsing, request adaptation logic and update related tests (@terabytesoftw)

## 0.1.2 December 11, 2025

- Bug #190: Update action versions to use `yii2-framework` for consistency (@terabytesoftw)
- Bug #191: Refactor test imports for consistency and add support files (@terabytesoftw)
- Bug #192: Add copyright and license information to `MockerExtension::class` and refactor `tests/support/bootstrap.php` (@terabytesoftw)
- Bug #193: Update `.editorconfig` and `.gitignore` for improved consistency and clarity (@terabytesoftw)
- Dep #194: `Update symplify/easy-coding-standard requirement from` `^12.5` to `^13.0` (@dependabot)
- Bug #195: Add test for memory usage at `90%` threshold in `ApplicationMemoryTest` class (@terabytesoftw)
- Bug #196: Fix proper bridge between PSR-7 `ServerRequestInterface` and Yii `Request` class, including request body parsing (@Blezigen)
- Bug #197: Add memory limit option to `mutation-static` command in `composer.json` for improved mutation testing (@terabytesoftw)
- Bug #198: Refactor `setPsr7Request()` method to use standardized error messages and introduce comprehensive integration tests using `StatelessApplication` class (@terabytesoftw)
- Bug #199: Update `README.md` and `docs/configuration.md` to include automatic body parsing setup and parser configuration (@terabytesoftw)
- Bug #200: Add missing GitHub agents directory to ignore list `.gitignore` (@terabytesoftw)

## 0.1.1 October 6, 2025

- Bug #180: Add `phpunit-dev` job to `build.yml` for enhanced testing capabilities (@terabytesoftw)
- Bug #181: Add permissions to workflow files for enhanced access control (@terabytesoftw)
- Bug #182: Remove redundant cookie collection tests in `CookiesPsr7Test` for clarity (@terabytesoftw)
- Bug #183: Remove redundant cookie tests from `ResponseAdapterTest` for clarity (@terabytesoftw)
- Bug #184: Remove redundant remote IP and server port from `ServerParamsPsr7Test` for clarity (@terabytesoftw)
- Bug #185: Consolidate `paths-ignore` configuration across workflow files for consistency, add logos for badges in `README.md` (@terabytesoftw)
- Bug #186: Remove redundant test cases from `ServerRequestAdapterTest` for clarity (@terabytesoftw)
- Bug #187: Correct grammatical error in docblock for `createErrorResponse` method in `ErrorHandler` (@terabytesoftw)
- Bug #188: Update PHPDocs in tests for consistency and clarity (@terabytesoftw)
- Bug #189: Add mutation testing badge to `README.md` for enhanced visibility (@terabytesoftw)

## 0.1.0 September 26, 2025

- Initial release
