# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Bind `$this` in Pest's `test()`, `it()`, `beforeEach()` and `afterEach()` closures to the configured TestCase.
- Stop reporting `InternalMethod` on Pest's public test-writing API.
