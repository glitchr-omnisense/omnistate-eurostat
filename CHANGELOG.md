# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

### Added
- The first release: Eurostat's API Statistics (JSON-stat 2.0) as an Omnistate statistics source -
  a series by its dataset and filters, its flags, the table of contents searched; `policy.json`
  (CC BY 4.0, the source acknowledged, limits unknown). Tests on Eurostat's answers recorded on
  2026-11-10, the family's conformance suite.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnistate: ^1.0`: stable versions, so `composer require omnistate/eurostat` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).

