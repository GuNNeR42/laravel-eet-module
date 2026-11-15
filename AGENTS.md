# EET 2.0 Module

Every agent reads this file **before doing any work**. It is binding. When a
role definition in `agents/` or a local skill in `.agents/skills/` contradicts
this file, this file wins.

This file is the single source of agent instructions for every tool (Claude
Code, Codex, Cursor, Copilot, Gemini, …). Tool-specific files such as
`CLAUDE.md` and `GEMINI.md` only point here — never put instructions in them.

## About the Project

This repository is a Laravel package, `gunner42/laravel-eet-module`
(namespace `Gunner42\LaravelEetModule\`), installed into host Laravel applications via
Composer. It is not an application: there is no `app/` directory, no frontend,
and no deployment. The package is exercised through Orchestra Testbench and the
`workbench/` host application.

[modul pro evidenci tržeb podle zákona o elektronické evidenci tržeb. doplnit celý popis]

## Agent Roles

Work is split into roles defined in [`agents/`](agents/README.md): architect,
tester, implementer, devops, reviewer, security. When you are asked to act as a
role, read `agents/README.md` and the role file first, and stay within that
role's input and write permissions. When no role is given, follow this file
and ask which role applies before writing to `spec/` or `tests/`.

## Source of Truth

`spec/` is the source of truth. Code follows the specification, never the
other way around.

When you hit something the specification does not cover: **do not invent an
answer.** Stop, add the question to `spec/99-open-questions.md`, and wait for a
human decision. An invented rule is worse than a missing one, because it looks
approved.

| File | Purpose |
|---|---|
| `spec/_template.md` | template for every feature specification |
| `spec/00-brief.md` | the briefs from the human and the decisions that frame them |
| `spec/zadani/` | input documents from the human; not binding where a feature spec covers the topic |
| `spec/02-glossary.md` | binding glossary: Czech term ↔ code identifier; one concept = one word |
| `spec/05-infrastructure.md` | CI/CD contract: support matrix (PHP, Laravel, Testbench, OS, stability lanes), workflows, release automation |
| `spec/10-interface.md` | public contract of the package (see [Public Interface](#public-interface)) |
| `spec/NN-<feature>.md` | feature specifications with numbered rules (`R1`, `R2`, …) |
| `spec/99-open-questions.md` | unanswered questions; each gets a one-line answer from the human |

## Language

- Everything code related is in **English**: code, identifiers, file names,
  comments, docblocks and commit messages.
- **Czech** is used for specifications (`spec/`) and internal documentation.
  Public package documentation distributed via Packagist (`README.md`,
  `CHANGELOG.md`, the bundled Boost skill in `resources/boost/`) stays in
  English.
- Use the vocabulary from `spec/02-glossary.md` (column "V kódu" for
  identifiers, column "Pojem" for user-facing text and translations). One
  concept = one word, in the spec, the code and the tests; never a word from
  the "Nepoužívat" column.
- Error/state codes in enums use TitleCase keys (`CapacityExceeded`).

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape
  (`src/EetServiceProvider.php`) before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags,
  documentation, and examples aligned with `gunner42/laravel-eet-module`.
- Add only the files and dependencies needed for the package behavior being
  implemented.
- Prefer explicit Laravel package code over helper abstractions unless the
  extension point is real (named in `spec/10-interface.md`).
- Keep tests focused on observable package behavior through public APIs,
  service provider wiring, commands, routes, published resources, and
  documentation promises.
- Everything the host application may override (config, views, translations,
  migrations, assets) is publishable under the tags listed in
  `spec/10-interface.md`.

## Technical Rules

- Support matrix: PHP `^8.3`, Laravel 12 and 13 (`illuminate/support`),
  Orchestra Testbench 10 and 11, Pest 4 and 5, Linux and Windows. Code must
  run on the lowest supported versions — no syntax or APIs newer than the
  matrix allows (see the `package-compatibility` skill).
- Every PHP file declares `strict_types=1` (enforced by `tests/ArchTest.php`).
  No `dd()`, `ddd()`, `env()` or `exit()` in package code; read configuration
  through `config()`.
- Keep domain logic in `src/`, organized by kind
  (`src/{Models,Enums,Data,Actions,Services,Jobs,Events,Exceptions,Contracts}`,
  see `spec/10-interface.md`), not in the service provider, controllers,
  console commands or Blade views. State changes go only through Actions (one
  public `handle()`); Services are stateless computations. Controllers and
  commands validate, authorize, delegate, and render/output.
- Money, quantities and VAT rates are value objects defined in the
  specification; `float` is forbidden in `src/`.
- Never read the clock directly (`time()`, `new DateTime()`, `date()`). Use
  `now()` / `CarbonImmutable` so tests can freeze time with
  `$this->travelTo()`.
- When the package exposes HTTP routes: validate input with Form Requests,
  authorize with policies or gates, and let the host configure middleware.
- Expected business-rule failures are **explicit outcomes** — a validation
  error or a dedicated domain exception (in `src/Exceptions`) mapped to a
  defined response, as described in the spec. Never a silent fallback or a
  default value for an unknown state.
- PHPStan level 7 and 100 % type coverage must stay green.
- No new Composer dependencies without human approval. Every runtime
  dependency becomes a dependency of every host application.

## Write Permissions

| Path | Who may write |
|---|---|
| `spec/` | architect, human |
| `tests/` | tester |
| `src/`, `config/`, `database/`, `routes/`, `resources/` (incl. `resources/boost/`), `lang/`, `public/`, `workbench/`, `README.md` | implementer |
| `.github/workflows/`, `.github/dependabot.yml`, `.github/release.yml` | devops |
| `AGENTS.md`, `CLAUDE.md`, `agents/`, `.agents/` (skills), `CHANGELOG.md`, other `.github/` files, tooling config (`composer.json`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `testbench.yaml`, `.editorconfig`, `.gitattributes`, `.gitignore`) | human only |
| `vendor/`, `build/`, `.phpunit.cache/`, `composer.lock`, generated workbench files (`workbench/storage/`, SQLite database) | **nobody** — generated |

An agent without write permission for a path must not change files in it, not
even "just a small fix". If it sees a problem there, it reports it.

## Public Interface

`spec/10-interface.md` is the public contract of the package: facade and
public class methods (signatures, return types, thrown exceptions), contracts
and extension points, config keys and their defaults, publish tags and the
paths they publish, Artisan commands with their arguments, options and exit
codes, events, named routes with HTTP methods and payloads, database tables
and columns, model attributes and factory states, and translation keys.

The implementation must match it exactly. Tests are written against that
contract before the code exists, so any deviation breaks them — change the
spec first, never the contract silently. A change to the public contract is a
breaking change for host applications and must be called out for the
changelog.

## Tests

- Tests are written **from the specification**, not from the implementation.
- The tester must not read `src/`, `config/`, `routes/`, `resources/views/`,
  `database/migrations/`, `workbench/app/`, or `.github/workflows/`.
- Every numbered rule in the specification has at least one test that
  verifies it.
- The test name describes the rule, not the function, and ends with the rule
  reference: `it('rejects a sale without a VAT rate [R5]')`.
- Prefer feature tests that go through the public package surface (facade,
  Artisan commands, HTTP routes, published resources, container bindings) on
  top of `tests/TestCase.php`; use unit tests only for isolated domain logic.

## Quick Commands

- Full validation: `composer test` (PHPStan, Pint check, type coverage, Pest)
- Formatting: `composer lint` / check only: `composer lint:check`
- Static analysis: `composer analyse`
- Type coverage: `composer test:types`
- Pest tests: `composer test:unit` (filter: `composer test:unit -- --filter=...`)
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

Skills live in `.agents/skills/` (`.claude` is a symlink to `.agents`). They
describe *how* to do a task; this file and the role files decide *who* may do
it and *where*.

- `package-scaffold`: use when adding package capabilities or wiring them
  through the service provider, including commands, migrations, routes,
  config, views, translations, assets, middleware, publish tags, workbench
  files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5
  and Orchestra Testbench. Its TDD loop is split between roles here: the
  tester writes the failing tests, the implementer makes them pass.
- `package-release`: use when preparing changelog, release notes, tags, or
  GitHub release workflow changes. Never publish autonomously.
- `package-compatibility`: use when reviewing code, dependencies, or CI
  against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from
  the package implementation, README, and examples.

## Definition of Done

1. `composer test` is green (PHPStan, Pint, type coverage, Pest). When
   `.github/` changed, the workflows pass `actionlint`.
2. Every rule in the specification has a matching test.
3. `spec/99-open-questions.md` is empty or contains only answered questions.
4. No file outside the role's allowed paths has changed.
5. When the public contract changed, `README.md` and the Boost skill in
   `resources/boost/` reflect it.

## Limits for Autonomous Runs

- At most **8 iterations** of the fix loop. After that, stop and report where
  you are stuck, even if only one failure remains.
- When the same test fails three times for the same reason, stop changing code
  and state whether you believe the error is in the specification or the test,
  and why.
- Never delete, skip (`->skip()`, `->todo()`), or weaken a test to get the build
  green.
